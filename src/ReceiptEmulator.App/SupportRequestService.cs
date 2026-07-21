using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.RegularExpressions;
using POSPrinterEmulator.Licensing;

namespace ReceiptEmulator;

public sealed record SupportRequestInput(
    string RequestType,
    string Subject,
    string Description,
    string? StepsToReproduce,
    string? ExpectedBehavior,
    string? ActualBehavior,
    string ContactName,
    string ContactEmail,
    bool IncludeDiagnostics,
    bool ConsentToSubmit,
    SupportAttachmentRequest[]? Attachments = null);

public sealed record SupportAttachmentRequest(string FileName, string ContentType, string ContentBase64);

public sealed record SupportAttachmentInput(string FileName, string ContentType, byte[] Content);

public sealed record SupportRequestResult(
    string Reference,
    string State,
    string Message,
    int? IssueNumber = null,
    string? IssueUrl = null);

internal sealed record SupportRequestDraft(
    string Reference,
    DateTimeOffset CreatedAt,
    SupportRequestInput Request,
    string ApplicationVersion,
    string LicenseTier,
    string LicenseId,
    string RegistrationDigest,
    string WindowsVersion,
    string ListenerSummary,
    string? Diagnostics,
    SupportAttachmentInput[] Attachments);

public sealed partial class SupportRequestService(
    HttpClient client,
    SupportLogProvider logs,
    ILogger<SupportRequestService> logger)
{
    internal const int MaximumAttachments = 3;
    internal const long MaximumAttachmentBytes = 5 * 1024 * 1024;
    internal const long MaximumTotalAttachmentBytes = 10 * 1024 * 1024;
    private static readonly string[] AllowedContentTypes =
        ["image/png", "image/jpeg", "text/plain", "application/zip"];
    private static readonly byte[] DraftEntropy = "POS Printer Emulator support draft v1"u8.ToArray();
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly string _draftRoot = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
        "POSPrinterEmulator", "SupportDrafts");

    public object Preview(SupportRequestInput input, string listenerSummary, IReadOnlyList<SupportAttachmentInput> attachments)
    {
        Validate(input, attachments);
        return new
        {
            ApplicationVersion = ProductInfo.Version,
            LicenseTier = "Collected at submission",
            WindowsVersion = Environment.OSVersion.VersionString,
            ListenerSummary = Redact(listenerSummary),
            Attachments = attachments.Select(file => new { file.FileName, file.ContentType, Size = file.Content.LongLength }),
            DiagnosticsIncluded = input.IncludeDiagnostics,
            RemovedByRedaction = new[]
            {
                "activation and maintenance keys", "authentication credentials", "receipt content",
                "email addresses outside the private contact field", "IP addresses", "Windows user names", "full file paths"
            }
        };
    }

    public async Task<SupportRequestResult> SubmitAsync(
        SupportRequestInput input,
        LicenseStatus license,
        string listenerSummary,
        IReadOnlyList<SupportAttachmentInput> attachments,
        CancellationToken cancellationToken)
    {
        Validate(input, attachments);
        if (!license.Features.Support || license.LicenseId is null)
            throw new InvalidOperationException("An active Application Maintenance and Support plan is required to submit a support request.");
        if (!input.ConsentToSubmit)
            throw new ArgumentException("Review the information and consent before submitting the support request.");

        var reference = $"PPE-{DateTime.UtcNow:yyyyMMdd}-{Convert.ToHexString(RandomNumberGenerator.GetBytes(4))}";
        var diagnosticText = input.IncludeDiagnostics ? Redact(logs.ReadLog()) : null;
        var draft = new SupportRequestDraft(
            reference,
            DateTimeOffset.UtcNow,
            input with
            {
                Subject = Redact(input.Subject),
                Description = Redact(input.Description),
                StepsToReproduce = Redact(input.StepsToReproduce),
                ExpectedBehavior = Redact(input.ExpectedBehavior),
                ActualBehavior = Redact(input.ActualBehavior),
                Attachments = []
            },
            ProductInfo.Version,
            license.Mode,
            license.LicenseId.Value.ToString("D"),
            ActivationKeyCodec.CreateRegistrationDigest(license.CustomerName, license.EmailAddress),
            Environment.OSVersion.VersionString,
            Redact(listenerSummary),
            diagnosticText,
            attachments.Select(file => file with { FileName = SafeFileName(file.FileName) }).ToArray());

        try
        {
            var result = await SendAsync(draft, cancellationToken);
            DeleteDraftFile(reference);
            return result;
        }
        catch (Exception exception) when (exception is HttpRequestException or TaskCanceledException or InvalidDataException)
        {
            logger.LogWarning(exception, "Support request {Reference} was saved for retry", reference);
            await SaveDraftAsync(draft, cancellationToken);
            return new SupportRequestResult(reference, "SavedForRetry",
                "The support request was saved on this computer. You can retry it from Support without re-entering the information.");
        }
    }

    public static IReadOnlyList<SupportAttachmentInput> DecodeAttachments(SupportAttachmentRequest[]? attachments)
    {
        if (attachments is null || attachments.Length == 0) return [];
        if (attachments.Length > MaximumAttachments)
            throw new ArgumentException("Attach no more than three files.");

        var decoded = new List<SupportAttachmentInput>(attachments.Length);
        foreach (var attachment in attachments)
        {
            byte[] content;
            try { content = Convert.FromBase64String(attachment.ContentBase64); }
            catch (FormatException) { throw new ArgumentException($"Attachment {attachment.FileName} is not valid."); }
            decoded.Add(new SupportAttachmentInput(attachment.FileName, attachment.ContentType, content));
        }
        return decoded;
    }

    public IReadOnlyList<object> ListDrafts()
    {
        if (!Directory.Exists(_draftRoot)) return [];
        return Directory.EnumerateFiles(_draftRoot, "*.json", SearchOption.TopDirectoryOnly)
            .Select(ReadDraft)
            .Where(draft => draft is not null)
            .Cast<SupportRequestDraft>()
            .OrderByDescending(draft => draft.CreatedAt)
            .Select(draft => (object)new
            {
                draft.Reference,
                draft.CreatedAt,
                draft.Request.RequestType,
                draft.Request.Subject
            }).ToArray();
    }

    public async Task<SupportRequestResult> RetryAsync(string reference, CancellationToken cancellationToken)
    {
        var draft = ReadDraft(DraftPath(reference)) ?? throw new KeyNotFoundException("The saved support request was not found.");
        var result = await SendAsync(draft, cancellationToken);
        DeleteDraftFile(reference);
        return result;
    }

    public bool DeleteDraft(string reference)
    {
        var path = DraftPath(reference);
        if (!File.Exists(path)) return false;
        File.Delete(path);
        return true;
    }

    private async Task<SupportRequestResult> SendAsync(SupportRequestDraft draft, CancellationToken cancellationToken)
    {
        using var response = await client.PostAsJsonAsync("api/support-request.php", draft, cancellationToken);
        if (!response.IsSuccessStatusCode)
            throw new HttpRequestException($"Support service returned HTTP {(int)response.StatusCode}.");
        return await response.Content.ReadFromJsonAsync<SupportRequestResult>(cancellationToken: cancellationToken)
               ?? throw new InvalidDataException("The support service returned an empty response.");
    }

    private async Task SaveDraftAsync(SupportRequestDraft draft, CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("Protected support drafts require Windows.");
        await _gate.WaitAsync(cancellationToken);
        try
        {
            Directory.CreateDirectory(_draftRoot);
            var temporary = DraftPath(draft.Reference) + ".tmp";
            var serialized = JsonSerializer.SerializeToUtf8Bytes(draft);
            var protectedBytes = ProtectedData.Protect(serialized, DraftEntropy, DataProtectionScope.CurrentUser);
            await File.WriteAllBytesAsync(temporary, protectedBytes, cancellationToken);
            File.Move(temporary, DraftPath(draft.Reference), true);
        }
        finally { _gate.Release(); }
    }

    private SupportRequestDraft? ReadDraft(string path)
    {
        try
        {
            if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("Protected support drafts require Windows.");
            var protectedBytes = File.ReadAllBytes(path);
            var serialized = ProtectedData.Unprotect(protectedBytes, DraftEntropy, DataProtectionScope.CurrentUser);
            return JsonSerializer.Deserialize<SupportRequestDraft>(serialized);
        }
        catch (Exception exception) { logger.LogWarning(exception, "Ignoring unreadable support draft {Path}", Path.GetFileName(path)); return null; }
    }

    private string DraftPath(string reference)
    {
        if (!ReferencePattern().IsMatch(reference)) throw new ArgumentException("The support reference is invalid.");
        return Path.Combine(_draftRoot, reference + ".json");
    }

    private void DeleteDraftFile(string reference)
    {
        try { var path = DraftPath(reference); if (File.Exists(path)) File.Delete(path); }
        catch (Exception exception) { logger.LogWarning(exception, "Support draft {Reference} could not be removed", reference); }
    }

    private static void Validate(SupportRequestInput input, IReadOnlyList<SupportAttachmentInput> attachments)
    {
        if (!new[] { "Bug Report", "Feature Request", "License Issue", "Other Issue" }.Contains(input.RequestType))
            throw new ArgumentException("Choose a valid support request type.");
        if (string.IsNullOrWhiteSpace(input.Subject) || input.Subject.Trim().Length > 160)
            throw new ArgumentException("Enter a subject of 160 characters or fewer.");
        if (string.IsNullOrWhiteSpace(input.Description) || input.Description.Trim().Length > 8000)
            throw new ArgumentException("Enter a detailed description of 8,000 characters or fewer.");
        if (string.IsNullOrWhiteSpace(input.ContactName) || input.ContactName.Trim().Length > 160)
            throw new ArgumentException("Enter a contact name.");
        if (!EmailPattern().IsMatch(input.ContactEmail) || input.ContactEmail.Length > 254)
            throw new ArgumentException("Enter a valid contact email address.");
        if (attachments.Count > MaximumAttachments || attachments.Sum(file => file.Content.LongLength) > MaximumTotalAttachmentBytes)
            throw new ArgumentException("Attach no more than three files totaling 10 MB.");
        if (attachments.Any(file => file.Content.LongLength is <= 0 or > MaximumAttachmentBytes || !AllowedContentTypes.Contains(file.ContentType)))
            throw new ArgumentException("Attachments must be PNG, JPEG, TXT, LOG, or ZIP files no larger than 5 MB each.");
        foreach (var attachment in attachments) ValidateAttachmentContent(attachment);
    }

    private static void ValidateAttachmentContent(SupportAttachmentInput attachment)
    {
        var extension = Path.GetExtension(attachment.FileName).ToLowerInvariant();
        var valid = extension switch
        {
            ".png" => attachment.ContentType == "image/png" && attachment.Content.AsSpan().StartsWith(new byte[] { 0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A }),
            ".jpg" or ".jpeg" => attachment.ContentType == "image/jpeg" && attachment.Content.AsSpan().StartsWith(new byte[] { 0xFF, 0xD8, 0xFF }),
            ".zip" => attachment.ContentType == "application/zip" && attachment.Content.AsSpan().StartsWith(new byte[] { 0x50, 0x4B, 0x03, 0x04 }),
            ".txt" or ".log" => attachment.ContentType == "text/plain" && IsUtf8Text(attachment.Content),
            _ => false
        };
        if (!valid) throw new ArgumentException($"Attachment {attachment.FileName} does not match its allowed file type.");
    }

    private static bool IsUtf8Text(byte[] content)
    {
        if (content.Contains((byte)0)) return false;
        try { _ = new System.Text.UTF8Encoding(false, true).GetString(content); return true; }
        catch (System.Text.DecoderFallbackException) { return false; }
    }

    internal static string Redact(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return string.Empty;
        var redacted = LicenseKeyPattern().Replace(value, "[license key removed]");
        redacted = EmailPatternGlobal().Replace(redacted, "[email removed]");
        redacted = IpAddressPattern().Replace(redacted, "[IP address removed]");
        redacted = WindowsPathPattern().Replace(redacted, "[local path removed]");
        redacted = CredentialLinePattern().Replace(redacted, "$1[credential removed]");
        redacted = UserNameLinePattern().Replace(redacted, "$1[Windows user removed]");
        redacted = PaymentCardPattern().Replace(redacted, "[payment number removed]");
        return redacted.Length > 256_000 ? redacted[..256_000] + Environment.NewLine + "[diagnostics truncated]" : redacted;
    }

    private static string SafeFileName(string name)
    {
        var cleaned = Path.GetFileName(name);
        foreach (var invalid in Path.GetInvalidFileNameChars()) cleaned = cleaned.Replace(invalid, '_');
        return string.IsNullOrWhiteSpace(cleaned) ? "attachment" : cleaned[..Math.Min(cleaned.Length, 120)];
    }

    [GeneratedRegex(@"^PPE-[0-9]{8}-[A-F0-9]{8}$", RegexOptions.CultureInvariant)] private static partial Regex ReferencePattern();
    [GeneratedRegex(@"^[^\s@]+@[^\s@]+\.[^\s@]+$", RegexOptions.CultureInvariant)] private static partial Regex EmailPattern();
    [GeneratedRegex(@"(?i)\bPPE(?:M)?[0-9]*-[A-Za-z0-9_\-\.]+", RegexOptions.CultureInvariant)] private static partial Regex LicenseKeyPattern();
    [GeneratedRegex(@"(?i)\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b", RegexOptions.CultureInvariant)] private static partial Regex EmailPatternGlobal();
    [GeneratedRegex(@"(?<![0-9])(?:25[0-5]|2[0-4][0-9]|[01]?[0-9]?[0-9])(?:\.(?:25[0-5]|2[0-4][0-9]|[01]?[0-9]?[0-9])){3}(?![0-9])", RegexOptions.CultureInvariant)] private static partial Regex IpAddressPattern();
    [GeneratedRegex(@"(?i)\b[A-Z]:\\[^\r\n\t]+", RegexOptions.CultureInvariant)] private static partial Regex WindowsPathPattern();
    [GeneratedRegex(@"(?im)^([^\r\n]*(?:password|secret|token|credential)[^:=\r\n]*[:=]\s*).+$", RegexOptions.CultureInvariant)] private static partial Regex CredentialLinePattern();
    [GeneratedRegex(@"(?im)^([^\r\n]*(?:user(?:name)?)[^:=\r\n]*[:=]\s*).+$", RegexOptions.CultureInvariant)] private static partial Regex UserNameLinePattern();
    [GeneratedRegex(@"(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)", RegexOptions.CultureInvariant)] private static partial Regex PaymentCardPattern();
}
