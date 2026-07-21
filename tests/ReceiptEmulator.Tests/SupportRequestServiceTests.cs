using System.Text;
using Microsoft.Extensions.Logging.Abstractions;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class SupportRequestServiceTests
{
    [Fact]
    public void RedactsSensitiveDiagnosticValues()
    {
        const string source = "License PPE1-private-key\nEmail user@example.com\nHost 192.168.1.42\nPath C:\\Users\\Alice\\receipt.bin\nPassword: hunter2\nUsername: Alice\nCard: 4111 1111 1111 1111";

        var redacted = SupportRequestService.Redact(source);

        Assert.DoesNotContain("PPE1-private-key", redacted);
        Assert.DoesNotContain("user@example.com", redacted);
        Assert.DoesNotContain("192.168.1.42", redacted);
        Assert.DoesNotContain("Alice", redacted);
        Assert.DoesNotContain("hunter2", redacted);
        Assert.DoesNotContain("4111", redacted);
        Assert.Contains("[license key removed]", redacted);
        Assert.Contains("[credential removed]", redacted);
    }

    [Fact]
    public void DecodesAllowedAttachmentForValidationAndPreview()
    {
        var encoded = Convert.ToBase64String(Encoding.UTF8.GetBytes("redacted log"));
        var attachments = SupportRequestService.DecodeAttachments(
            [new SupportAttachmentRequest("support.log", "text/plain", encoded)]);
        var service = new SupportRequestService(new HttpClient(), new SupportLogProvider(), NullLogger<SupportRequestService>.Instance);

        var preview = service.Preview(ValidRequest(), "Primary: 127.0.0.1:9100", attachments);

        Assert.Single(attachments);
        Assert.Equal("redacted log", Encoding.UTF8.GetString(attachments[0].Content));
        Assert.DoesNotContain("127.0.0.1", System.Text.Json.JsonSerializer.Serialize(preview));
    }

    [Fact]
    public void RejectsMalformedAttachmentPayload()
    {
        var error = Assert.Throws<ArgumentException>(() => SupportRequestService.DecodeAttachments(
            [new SupportAttachmentRequest("bad.png", "image/png", "not-base64")]));
        Assert.Contains("not valid", error.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void RejectsUnsupportedAttachmentContentType()
    {
        var service = new SupportRequestService(new HttpClient(), new SupportLogProvider(), NullLogger<SupportRequestService>.Instance);
        var attachment = new SupportAttachmentInput("program.exe", "application/octet-stream", [1, 2, 3]);

        var error = Assert.Throws<ArgumentException>(() => service.Preview(ValidRequest(), "listener", [attachment]));

        Assert.Contains("PNG, JPEG, TXT, LOG, or ZIP", error.Message);
    }

    [Fact]
    public void RejectsAttachmentWhoseContentDoesNotMatchItsExtension()
    {
        var service = new SupportRequestService(new HttpClient(), new SupportLogProvider(), NullLogger<SupportRequestService>.Instance);
        var disguisedExecutable = new SupportAttachmentInput("screenshot.png", "image/png", Encoding.ASCII.GetBytes("MZ executable"));

        var error = Assert.Throws<ArgumentException>(() => service.Preview(ValidRequest(), "listener", [disguisedExecutable]));

        Assert.Contains("does not match", error.Message);
    }

    private static SupportRequestInput ValidRequest() => new(
        "Bug Report", "Receipt does not render", "The receipt preview is blank.", "Print a receipt", "Receipt appears", "Preview is blank",
        "Example Customer", "customer@example.com", true, false, []);
}
