using Microsoft.Extensions.Configuration;
using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class ExternalServicesOptionsTests
{
    [Fact]
    public void ProductionDefaultsRemainAvailable()
    {
        var options = ExternalServicesOptions.FromConfiguration(
            new ConfigurationBuilder().Build());

        options.Validate();

        Assert.Equal("Production", options.Profile);
        Assert.Equal("www.posprinteremulator.com", options.TelemetryEndpoint.Host);
        Assert.Equal("buy.posprinteremulator.com", options.BuyBaseUrl.Host);
    }

    [Fact]
    public void CertificationProfileRejectsAnyProductionHost()
    {
        var configuration = SandboxConfiguration();
        configuration["ExternalServices:TelemetryEndpoint"] =
            "https://www.posprinteremulator.com/api/v1/telemetry.php";
        var options = ExternalServicesOptions.FromConfiguration(
            new ConfigurationBuilder().AddInMemoryCollection(configuration).Build());

        var exception = Assert.Throws<InvalidOperationException>(options.Validate);

        Assert.Contains("refused to start", exception.Message, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("TelemetryEndpoint", exception.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void CompleteCertificationProfilePassesAndBuildsSandboxLinks()
    {
        var options = ExternalServicesOptions.FromConfiguration(
            new ConfigurationBuilder()
                .AddInMemoryCollection(SandboxConfiguration())
                .Build());

        options.Validate();

        Assert.True(options.IsCertification);
        Assert.Equal(
            "userportal-sandbox.posprinteremulator.com",
            new Uri(options.Links.CustomerPortalLicenses).Host);
        Assert.Equal(
            "buy-sandbox.posprinteremulator.com",
            new Uri(options.MaintenanceRenewalUrl("Pro")).Host);
    }

    [Fact]
    public void CertificationProfileRejectsProductionUrlReturnedBySandbox()
    {
        var options = ExternalServicesOptions.FromConfiguration(
            new ConfigurationBuilder()
                .AddInMemoryCollection(SandboxConfiguration())
                .Build());

        var exception = Assert.Throws<InvalidOperationException>(() =>
            options.ValidateReturnedUrl(
                "account-link verification URL",
                "https://userportal.posprinteremulator.com/verify"));

        Assert.Contains("unsafe production URL", exception.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void CertificationProfileRejectsUnknownThirdPartyHost()
    {
        var configuration = SandboxConfiguration();
        configuration["ExternalServices:SupportBaseUrl"] = "https://example.net/";
        var options = ExternalServicesOptions.FromConfiguration(
            new ConfigurationBuilder().AddInMemoryCollection(configuration).Build());

        var exception = Assert.Throws<InvalidOperationException>(options.Validate);

        Assert.Contains("outside the certification allowlist", exception.Message, StringComparison.OrdinalIgnoreCase);
    }

    private static Dictionary<string, string?> SandboxConfiguration() => new()
    {
        ["ExternalServices:Profile"] = "Certification",
        ["ExternalServices:TelemetryEndpoint"] =
            "https://sandbox.posprinteremulator.com/api/v1/telemetry.php",
        ["ExternalServices:AccountLinkEndpoint"] =
            "https://sandbox.posprinteremulator.com/api/v1/account-link.php",
        ["ExternalServices:DeviceEntitlementEndpoint"] =
            "https://admin-sandbox.posprinteremulator.com/api/v1/device-entitlement.php",
        ["ExternalServices:DeviceUnlinkEndpoint"] =
            "https://admin-sandbox.posprinteremulator.com/api/v1/device-unlink.php",
        ["ExternalServices:PromotionBaseUrl"] =
            "https://admin-sandbox.posprinteremulator.com/",
        ["ExternalServices:SupportBaseUrl"] =
            "https://admin-sandbox.posprinteremulator.com/",
        ["ExternalServices:BuyBaseUrl"] =
            "https://buy-sandbox.posprinteremulator.com/",
        ["ExternalServices:CustomerPortalBaseUrl"] =
            "https://userportal-sandbox.posprinteremulator.com/",
        ["ExternalServices:WebsiteBaseUrl"] =
            "https://sandbox.posprinteremulator.com/",
        ["ExternalServices:SupportWebsiteBaseUrl"] =
            "https://support-sandbox.posprinteremulator.com/",
    };
}
