using System.Text.Json;
using System.Text.Json.Serialization;

namespace ReceiptEmulator;

public sealed record PrinterCapabilities(
    bool Cutter,
    bool CashDrawer,
    bool RasterImages,
    bool NvGraphics,
    bool Barcodes,
    bool QrCodes,
    bool TwoColor,
    bool DleEotStatus,
    bool AutomaticStatusBack);

public sealed record PrinterProfile(
    string Id,
    string Name,
    string Description,
    bool BuiltIn,
    int PaperWidthMm,
    int PrintableDots,
    int MaximumRasterWidthDots,
    int MaximumRasterHeightDots,
    int DefaultCodePage,
    IReadOnlyList<int> SupportedCodePages,
    int FontAColumns,
    int FontBColumns,
    PrinterCapabilities Capabilities);

public sealed record PrinterProfileStatus(string SelectedProfileId, IReadOnlyList<PrinterProfile> Profiles);

public sealed record PrinterProfileInput(
    string Name,
    string? Description,
    int PaperWidthMm,
    int PrintableDots,
    int MaximumRasterWidthDots,
    int MaximumRasterHeightDots,
    int DefaultCodePage,
    IReadOnlyList<int>? SupportedCodePages,
    int FontAColumns,
    int FontBColumns,
    PrinterCapabilities Capabilities);

public sealed record PrinterProfileSelection(string ProfileId);

public sealed class PrinterProfileService
{
    public const string EpsonTmT88VId = "epson-tm-t88v-receipt5";
    public const string GenericEscPosId = "generic-esc-pos-80mm";
    public const string FileExtension = ".ppeprofile";
    public const int MaximumImportBytes = 128 * 1024;
    private const int SchemaVersion = 1;
    private readonly object _sync = new();
    private readonly string _profilesPath;
    private readonly string _selectionPath;
    private List<PrinterProfile> _customProfiles;
    private string _selectedProfileId;

    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = true,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull
    };

    private static readonly PrinterProfile[] BuiltIns =
    [
        new(
            EpsonTmT88VId,
            "EPSON TM-T88V Receipt5",
            "Tested Epson Advanced Printer Driver configuration for an 80 mm TM-T88V receipt printer.",
            true, 80, 576, 576, 2304, 437,
            [437, 850, 852, 855, 857, 858, 860, 862, 863, 864, 865, 866, 874, 1252],
            48, 64,
            new PrinterCapabilities(true, true, true, true, true, true, true, true, true)),
        new(
            GenericEscPosId,
            "Generic ESC/POS 80 mm",
            "Conservative 80 mm profile for common ESC/POS-compatible receipt printers.",
            true, 80, 512, 512, 1024, 437,
            [437, 850, 858, 1252],
            42, 56,
            new PrinterCapabilities(true, true, true, false, true, true, false, true, true))
    ];

    public PrinterProfileService(LicenseService license)
    {
        _profilesPath = Path.Combine(license.RootPath, "printer-profiles.json");
        _selectionPath = Path.Combine(license.RootPath, "selected-printer-profile.json");
        _customProfiles = [];
        foreach (var profile in Load<List<PrinterProfile>>(_profilesPath) ?? [])
        {
            try { if (!profile.BuiltIn) _customProfiles.Add(ValidateStored(profile)); }
            catch (ArgumentException) { /* One invalid custom profile does not prevent startup. */ }
        }
        _selectedProfileId = Load<SelectionState>(_selectionPath)?.ProfileId ?? EpsonTmT88VId;
        if (FindUnsafe(_selectedProfileId) is null) _selectedProfileId = EpsonTmT88VId;
    }

    public PrinterProfileStatus GetStatus()
    {
        lock (_sync) return new PrinterProfileStatus(_selectedProfileId, AllUnsafe());
    }

    public PrinterProfile GetSelected()
    {
        lock (_sync) return FindUnsafe(_selectedProfileId) ?? BuiltIns[0];
    }

    public PrinterProfile Select(string profileId)
    {
        lock (_sync)
        {
            var profile = FindUnsafe(profileId) ?? throw new ArgumentException("Choose an available printer profile.");
            _selectedProfileId = profile.Id;
            Save(_selectionPath, new SelectionState(profile.Id));
            return profile;
        }
    }

    public PrinterProfile Create(PrinterProfileInput input)
    {
        lock (_sync)
        {
            var idBase = Slug(input.Name);
            var id = idBase;
            for (var suffix = 2; FindUnsafe(id) is not null; suffix++) id = $"{idBase}-{suffix}";
            var profile = Validate(input, id);
            _customProfiles.Add(profile);
            SaveProfiles();
            return profile;
        }
    }

    public PrinterProfile Update(string id, PrinterProfileInput input)
    {
        lock (_sync)
        {
            if (BuiltIns.Any(profile => profile.Id == id)) throw new ArgumentException("Built-in profiles cannot be edited. Duplicate the profile first.");
            var index = _customProfiles.FindIndex(profile => profile.Id == id);
            if (index < 0) throw new KeyNotFoundException("The printer profile was not found.");
            var profile = Validate(input, id);
            _customProfiles[index] = profile;
            SaveProfiles();
            return profile;
        }
    }

    public PrinterProfile Duplicate(string id)
    {
        lock (_sync)
        {
            var source = FindUnsafe(id) ?? throw new KeyNotFoundException("The printer profile was not found.");
            return Create(new PrinterProfileInput(
                $"{source.Name} Copy", source.Description, source.PaperWidthMm, source.PrintableDots,
                source.MaximumRasterWidthDots, source.MaximumRasterHeightDots,
                source.DefaultCodePage, source.SupportedCodePages, source.FontAColumns, source.FontBColumns, source.Capabilities));
        }
    }

    public bool Delete(string id)
    {
        lock (_sync)
        {
            if (BuiltIns.Any(profile => profile.Id == id)) throw new ArgumentException("Built-in profiles cannot be deleted.");
            var removed = _customProfiles.RemoveAll(profile => profile.Id == id) > 0;
            if (!removed) return false;
            if (_selectedProfileId == id)
            {
                _selectedProfileId = EpsonTmT88VId;
                Save(_selectionPath, new SelectionState(_selectedProfileId));
            }
            SaveProfiles();
            return true;
        }
    }

    public byte[] Export(string id)
    {
        lock (_sync)
        {
            var profile = FindUnsafe(id) ?? throw new KeyNotFoundException("The printer profile was not found.");
            return JsonSerializer.SerializeToUtf8Bytes(new ProfileDocument("POS Printer Emulator Profile", SchemaVersion, profile with { BuiltIn = false }), JsonOptions);
        }
    }

    public async Task<PrinterProfile> ImportAsync(Stream input, CancellationToken cancellationToken = default)
    {
        using var output = new MemoryStream();
        var buffer = new byte[16 * 1024];
        while (true)
        {
            var read = await input.ReadAsync(buffer, cancellationToken);
            if (read == 0) break;
            if (output.Length + read > MaximumImportBytes) throw new InvalidDataException("Printer profile files must be 128 KB or smaller.");
            output.Write(buffer, 0, read);
        }
        ProfileDocument document;
        try
        {
            document = JsonSerializer.Deserialize<ProfileDocument>(output.ToArray(), JsonOptions)
                ?? throw new InvalidDataException("The printer profile file is empty or invalid.");
        }
        catch (JsonException)
        {
            throw new InvalidDataException("The printer profile file does not contain valid JSON.");
        }
        if (document.Format != "POS Printer Emulator Profile" || document.SchemaVersion != SchemaVersion)
            throw new InvalidDataException("This printer profile format is not supported.");
        var source = document.Profile;
        return Create(new PrinterProfileInput(source.Name, source.Description, source.PaperWidthMm, source.PrintableDots,
            source.MaximumRasterWidthDots, source.MaximumRasterHeightDots,
            source.DefaultCodePage, source.SupportedCodePages, source.FontAColumns, source.FontBColumns, source.Capabilities));
    }

    public void ApplyCapabilities(ParsedReceipt receipt, PrinterProfile profile)
    {
        for (var index = 0; index < receipt.Commands.Count; index++)
        {
            var command = receipt.Commands[index];
            if (!command.Supported) continue;
            string? reason = command.Name switch
            {
                "Cut paper" when !profile.Capabilities.Cutter => "paper cutting",
                "Generate drawer pulse" when !profile.Capabilities.CashDrawer => "cash-drawer pulses",
                "Print legacy bit image" or "Print raster image" when !profile.Capabilities.RasterImages => "raster images",
                "Print NV graphic" when !profile.Capabilities.NvGraphics => "stored NV graphics",
                "Print barcode" or "Set barcode width" or "Set barcode height" or "Set barcode text position" or "Select barcode text font"
                    when !profile.Capabilities.Barcodes => "barcodes",
                "Print QR code" or "Configure QR code" when !profile.Capabilities.QrCodes => "QR codes",
                "Select print color" when !profile.Capabilities.TwoColor && command.Details.Equals("red", StringComparison.OrdinalIgnoreCase) => "two-color printing",
                _ => null
            };
            if (reason is null && command.Name is "Print legacy bit image" or "Print raster image" && TryReadImageSize(command.Details, out var imageWidth, out var imageHeight) &&
                (imageWidth > profile.MaximumRasterWidthDots || imageHeight > profile.MaximumRasterHeightDots))
                reason = $"images larger than {profile.MaximumRasterWidthDots} x {profile.MaximumRasterHeightDots} dots";
            if (command.Name == "Select code page" && command.Details.StartsWith("CP", StringComparison.OrdinalIgnoreCase) &&
                int.TryParse(command.Details.AsSpan(2), out var codePage) && !profile.SupportedCodePages.Contains(codePage))
                reason = $"code page CP{codePage}";
            if (reason is not null)
                receipt.Commands[index] = command with { Supported = false, Details = $"{command.Details}; {reason} is not supported by {profile.Name}" };
        }
    }

    private IReadOnlyList<PrinterProfile> AllUnsafe() => [.. BuiltIns, .. _customProfiles.OrderBy(profile => profile.Name, StringComparer.OrdinalIgnoreCase)];
    private PrinterProfile? FindUnsafe(string id) => BuiltIns.Concat(_customProfiles).FirstOrDefault(profile => profile.Id.Equals(id, StringComparison.OrdinalIgnoreCase));
    private void SaveProfiles() => Save(_profilesPath, _customProfiles);

    private static PrinterProfile Validate(PrinterProfileInput input, string id)
    {
        var name = input.Name?.Trim() ?? string.Empty;
        if (name.Length is < 2 or > 80) throw new ArgumentException("Profile names must contain 2 to 80 characters.");
        if (input.PaperWidthMm is < 40 or > 120) throw new ArgumentException("Paper width must be between 40 and 120 mm.");
        if (input.PrintableDots is < 200 or > 1024) throw new ArgumentException("Printable width must be between 200 and 1024 dots.");
        if (input.MaximumRasterWidthDots is < 200 or > 2048 || input.MaximumRasterWidthDots > input.PrintableDots)
            throw new ArgumentException("Maximum raster width must be between 200 dots and the printable width.");
        if (input.MaximumRasterHeightDots is < 8 or > 8192) throw new ArgumentException("Maximum raster height must be between 8 and 8192 dots.");
        if (input.FontAColumns is < 20 or > 96 || input.FontBColumns is < 20 or > 128) throw new ArgumentException("Font columns are outside the supported range.");
        var pages = (input.SupportedCodePages ?? []).Distinct().Order().ToArray();
        if (pages.Length == 0 || pages.Any(page => page is < 1 or > 65535)) throw new ArgumentException("Choose at least one valid supported code page.");
        if (!pages.Contains(input.DefaultCodePage)) throw new ArgumentException("The default code page must be included in the supported code pages.");
        return new PrinterProfile(id, name, (input.Description ?? string.Empty).Trim(), false, input.PaperWidthMm,
            input.PrintableDots, input.MaximumRasterWidthDots, input.MaximumRasterHeightDots,
            input.DefaultCodePage, pages, input.FontAColumns, input.FontBColumns, input.Capabilities);
    }

    private static PrinterProfile ValidateStored(PrinterProfile profile) => Validate(new PrinterProfileInput(
        profile.Name, profile.Description, profile.PaperWidthMm, profile.PrintableDots,
        profile.MaximumRasterWidthDots, profile.MaximumRasterHeightDots, profile.DefaultCodePage,
        profile.SupportedCodePages, profile.FontAColumns, profile.FontBColumns, profile.Capabilities), profile.Id);

    private static bool TryReadImageSize(string details, out int width, out int height)
    {
        width = 0; height = 0;
        var parts = details.Split(' ', StringSplitOptions.RemoveEmptyEntries);
        return parts.Length >= 3 && int.TryParse(parts[0], out width) && parts[1] == "x" && int.TryParse(parts[2], out height);
    }

    private static string Slug(string value)
    {
        var slug = new string(value.Trim().ToLowerInvariant().Select(character => char.IsLetterOrDigit(character) ? character : '-').ToArray());
        while (slug.Contains("--", StringComparison.Ordinal)) slug = slug.Replace("--", "-", StringComparison.Ordinal);
        slug = slug.Trim('-');
        return string.IsNullOrWhiteSpace(slug) ? $"custom-{Guid.NewGuid():N}" : $"custom-{slug}";
    }

    private static T? Load<T>(string path)
    {
        try { return File.Exists(path) ? JsonSerializer.Deserialize<T>(File.ReadAllText(path), JsonOptions) : default; }
        catch { return default; }
    }

    private static void Save<T>(string path, T value)
    {
        var temporaryPath = path + ".tmp";
        File.WriteAllText(temporaryPath, JsonSerializer.Serialize(value, JsonOptions));
        File.Move(temporaryPath, path, true);
    }

    private sealed record SelectionState(string ProfileId);
    private sealed record ProfileDocument(string Format, int SchemaVersion, PrinterProfile Profile);
}
