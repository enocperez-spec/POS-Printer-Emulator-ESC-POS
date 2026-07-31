namespace ReceiptEmulator;

public sealed record ExternalServiceLinks(
    string Buy,
    string CustomerPortalLicenses,
    string Pricing,
    string Documentation,
    string Support);

public sealed class ExternalServicesOptions
{
    private static readonly HashSet<string> ProductionHosts = new(StringComparer.OrdinalIgnoreCase)
    {
        "posprinteremulator.com",
        "www.posprinteremulator.com",
        "admin.posprinteremulator.com",
        "buy.posprinteremulator.com",
        "userportal.posprinteremulator.com",
        "support.posprinteremulator.com",
    };
    private static readonly HashSet<string> CertificationHosts = new(StringComparer.OrdinalIgnoreCase)
    {
        "sandbox.posprinteremulator.com",
        "admin-sandbox.posprinteremulator.com",
        "buy-sandbox.posprinteremulator.com",
        "userportal-sandbox.posprinteremulator.com",
        "support-sandbox.posprinteremulator.com",
    };

    public required string Profile { get; init; }
    public required Uri TelemetryEndpoint { get; init; }
    public required Uri AccountLinkEndpoint { get; init; }
    public required Uri DeviceEntitlementEndpoint { get; init; }
    public required Uri DeviceUnlinkEndpoint { get; init; }
    public required Uri PromotionBaseUrl { get; init; }
    public required Uri SupportBaseUrl { get; init; }
    public required Uri BuyBaseUrl { get; init; }
    public required Uri CustomerPortalBaseUrl { get; init; }
    public required Uri WebsiteBaseUrl { get; init; }
    public required Uri SupportWebsiteBaseUrl { get; init; }

    public bool IsCertification =>
        Profile.Equals("Certification", StringComparison.OrdinalIgnoreCase) ||
        Profile.Equals("Sandbox", StringComparison.OrdinalIgnoreCase) ||
        Profile.Equals("Staging", StringComparison.OrdinalIgnoreCase);

    public ExternalServiceLinks Links => new(
        Buy: BuyBaseUrl.AbsoluteUri,
        CustomerPortalLicenses: new Uri(CustomerPortalBaseUrl, "portal.php?page=licenses").AbsoluteUri,
        Pricing: new Uri(WebsiteBaseUrl, "pricing").AbsoluteUri,
        Documentation: new Uri(WebsiteBaseUrl, "documentation").AbsoluteUri,
        Support: SupportWebsiteBaseUrl.AbsoluteUri);

    public string MaintenanceRenewalUrl(string tier) =>
        new Uri(BuyBaseUrl, $"?product=maintenance&tier={Uri.EscapeDataString(tier)}").AbsoluteUri;

    public static ExternalServicesOptions FromConfiguration(IConfiguration configuration)
    {
        var profile = configuration["ExternalServices:Profile"]?.Trim();
        if (string.IsNullOrWhiteSpace(profile)) profile = "Production";

        return new ExternalServicesOptions
        {
            Profile = profile,
            TelemetryEndpoint = ReadHttpsUri(
                configuration,
                "ExternalServices:TelemetryEndpoint",
                "https://www.posprinteremulator.com/api/v1/telemetry.php",
                "Telemetry:Endpoint"),
            AccountLinkEndpoint = ReadHttpsUri(
                configuration,
                "ExternalServices:AccountLinkEndpoint",
                "https://www.posprinteremulator.com/api/v1/account-link.php",
                "AccountLink:Endpoint"),
            DeviceEntitlementEndpoint = ReadHttpsUri(
                configuration,
                "ExternalServices:DeviceEntitlementEndpoint",
                "https://admin.posprinteremulator.com/api/v1/device-entitlement.php",
                "AccountLink:EntitlementEndpoint"),
            DeviceUnlinkEndpoint = ReadHttpsUri(
                configuration,
                "ExternalServices:DeviceUnlinkEndpoint",
                "https://admin.posprinteremulator.com/api/v1/device-unlink.php",
                "AccountLink:UnlinkEndpoint"),
            PromotionBaseUrl = ReadHttpsBaseUri(
                configuration,
                "ExternalServices:PromotionBaseUrl",
                "https://admin.posprinteremulator.com/"),
            SupportBaseUrl = ReadHttpsBaseUri(
                configuration,
                "ExternalServices:SupportBaseUrl",
                "https://admin.posprinteremulator.com/"),
            BuyBaseUrl = ReadHttpsBaseUri(
                configuration,
                "ExternalServices:BuyBaseUrl",
                "https://buy.posprinteremulator.com/"),
            CustomerPortalBaseUrl = ReadHttpsBaseUri(
                configuration,
                "ExternalServices:CustomerPortalBaseUrl",
                "https://userportal.posprinteremulator.com/"),
            WebsiteBaseUrl = ReadHttpsBaseUri(
                configuration,
                "ExternalServices:WebsiteBaseUrl",
                "https://www.posprinteremulator.com/"),
            SupportWebsiteBaseUrl = ReadHttpsBaseUri(
                configuration,
                "ExternalServices:SupportWebsiteBaseUrl",
                "https://support.posprinteremulator.com/"),
        };
    }

    public void Validate()
    {
        if (!Profile.Equals("Production", StringComparison.OrdinalIgnoreCase) && !IsCertification)
        {
            throw new InvalidOperationException(
                "ExternalServices:Profile must be Production, Certification, Sandbox, or Staging.");
        }
        if (!IsCertification) return;

        var configured = new Dictionary<string, Uri>
        {
            [nameof(TelemetryEndpoint)] = TelemetryEndpoint,
            [nameof(AccountLinkEndpoint)] = AccountLinkEndpoint,
            [nameof(DeviceEntitlementEndpoint)] = DeviceEntitlementEndpoint,
            [nameof(DeviceUnlinkEndpoint)] = DeviceUnlinkEndpoint,
            [nameof(PromotionBaseUrl)] = PromotionBaseUrl,
            [nameof(SupportBaseUrl)] = SupportBaseUrl,
            [nameof(BuyBaseUrl)] = BuyBaseUrl,
            [nameof(CustomerPortalBaseUrl)] = CustomerPortalBaseUrl,
            [nameof(WebsiteBaseUrl)] = WebsiteBaseUrl,
            [nameof(SupportWebsiteBaseUrl)] = SupportWebsiteBaseUrl,
        };
        var unsafeEndpoints = configured
            .Where(pair => ProductionHosts.Contains(pair.Value.Host))
            .Select(pair => $"{pair.Key}={pair.Value.Host}")
            .ToArray();
        if (unsafeEndpoints.Length > 0)
        {
            throw new InvalidOperationException(
                $"The {Profile} external-services profile is unsafe because it contains production hosts: " +
                string.Join(", ", unsafeEndpoints) +
                ". The application refused to start before making an external request.");
        }
        var unexpectedEndpoints = configured
            .Where(pair => !CertificationHosts.Contains(pair.Value.Host))
            .Select(pair => $"{pair.Key}={pair.Value.Host}")
            .ToArray();
        if (unexpectedEndpoints.Length > 0)
        {
            throw new InvalidOperationException(
                $"The {Profile} external-services profile contains hosts outside the certification allowlist: " +
                string.Join(", ", unexpectedEndpoints) +
                ". The application refused to start before making an external request.");
        }
    }

    public void ValidateReturnedUrl(string name, string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return;
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri) ||
            uri.Scheme != Uri.UriSchemeHttps)
        {
            throw new InvalidOperationException($"{name} must be a valid HTTPS URL.");
        }
        if (IsCertification && ProductionHosts.Contains(uri.Host))
        {
            throw new InvalidOperationException(
                $"The {Profile} service returned an unsafe production URL for {name}. " +
                "The application refused to open it.");
        }
        if (IsCertification && !CertificationHosts.Contains(uri.Host))
        {
            throw new InvalidOperationException(
                $"The {Profile} service returned a URL outside the certification allowlist for {name}. " +
                "The application refused to open it.");
        }
    }

    private static Uri ReadHttpsBaseUri(
        IConfiguration configuration,
        string key,
        string fallback)
    {
        var uri = ReadHttpsUri(configuration, key, fallback);
        return uri.AbsoluteUri.EndsWith("/", StringComparison.Ordinal)
            ? uri
            : new Uri(uri.AbsoluteUri + "/", UriKind.Absolute);
    }

    private static Uri ReadHttpsUri(
        IConfiguration configuration,
        string key,
        string fallback,
        string? legacyKey = null)
    {
        var value = configuration[key];
        if (string.IsNullOrWhiteSpace(value) && legacyKey is not null)
        {
            value = configuration[legacyKey];
        }
        value = string.IsNullOrWhiteSpace(value) ? fallback : value.Trim();
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri) ||
            uri.Scheme != Uri.UriSchemeHttps ||
            string.IsNullOrWhiteSpace(uri.Host))
        {
            throw new InvalidOperationException($"{key} must be a valid HTTPS URL.");
        }
        return uri;
    }
}
