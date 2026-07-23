using System.Diagnostics;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Xml.Linq;

return await ReceiptLabBuild.RunAsync(args);

internal static class ReceiptLabBuild
{
    private const string BuildConfiguration = "Release";
    private static readonly string Root = FindProjectRoot();
    private static readonly string AppProject = Path.Combine(Root, "src", "ReceiptEmulator.App", "ReceiptEmulator.App.csproj");
    private static readonly string DesktopProject = Path.Combine(Root, "src", "POSPrinterEmulator.Desktop", "POSPrinterEmulator.Desktop.csproj");
    private static readonly string UpdaterProject = Path.Combine(Root, "src", "POSPrinterEmulator.Updater", "POSPrinterEmulator.Updater.csproj");
    private static readonly string LicenseToolProject = Path.Combine(Root, "tools", "POSPrinterEmulator.LicenseTool", "POSPrinterEmulator.LicenseTool.csproj");
    private static readonly string LicenseManagerProject = Path.Combine(Root, "tools", "POSPrinterEmulator.LicenseManager", "POSPrinterEmulator.LicenseManager.csproj");
    private static readonly string TestProject = Path.Combine(Root, "tests", "ReceiptEmulator.Tests", "ReceiptEmulator.Tests.csproj");
    private static readonly string ViewerDirectory = Path.Combine(Root, "src", "ReceiptEmulator.Viewer");
    private static readonly string WebRoot = Path.Combine(Root, "src", "ReceiptEmulator.App", "wwwroot");
    private static readonly string PublishDirectory = Path.Combine(Root, "artifacts", "win-x64");
    private static readonly string UpdaterPublishDirectory = Path.Combine(Root, "artifacts", "updater", "win-x64");
    private static readonly string PrerequisitesDirectory = Path.Combine(Root, "artifacts", "prerequisites");
    private static readonly string WebView2Bootstrapper = Path.Combine(PrerequisitesDirectory, "MicrosoftEdgeWebview2Setup.exe");

    public static async Task<int> RunAsync(string[] arguments)
    {
        try
        {
            var command = arguments.FirstOrDefault()?.ToLowerInvariant() ?? "help";
            switch (command)
            {
                case "build":
                    await BuildAsync();
                    break;
                case "test":
                    await TestAsync();
                    break;
                case "publish":
                    await PublishAsync();
                    break;
                case "installer":
                case "all":
                    await InstallerAsync(arguments.Skip(1).Contains("--skip-publish", StringComparer.OrdinalIgnoreCase));
                    break;
                case "send-sample":
                    await SendSampleAsync(arguments.Skip(1).ToArray());
                    break;
                case "license-manager":
                    await PublishLicenseManagerAsync();
                    break;
                case "sync-release":
                    SynchronizeReleaseVersion(arguments.Skip(1).Contains("--check", StringComparer.OrdinalIgnoreCase));
                    break;
                case "check-seo":
                    CheckWebsiteSeo();
                    break;
                case "help":
                case "--help":
                case "-h":
                    PrintHelp();
                    break;
                default:
                    throw new ArgumentException($"Unknown command '{command}'. Run with 'help' to see the available commands.");
            }

            return 0;
        }
        catch (Exception exception)
        {
            Console.Error.WriteLine($"POS Printer Emulator build failed: {exception.Message}");
            return 1;
        }
    }

    private static async Task BuildAsync()
    {
        Console.WriteLine("Building the POS Printer Emulator viewer...");
        DeleteDirectoryInsideWorkspace(WebRoot);
        var pnpm = FindRequiredCommand("pnpm", "pnpm.cmd", "pnpm.exe");
        await RunProcessAsync(pnpm, ["install", "--frozen-lockfile"], ViewerDirectory);
        await RunProcessAsync(pnpm, ["run", "build"], ViewerDirectory);

        Console.WriteLine("Building the POS Printer Emulator service and desktop application...");
        await RunProcessAsync("dotnet", ["build", AppProject, "-c", BuildConfiguration], Root);
        await RunProcessAsync("dotnet", ["build", DesktopProject, "-c", BuildConfiguration], Root);
        await RunProcessAsync("dotnet", ["build", UpdaterProject, "-c", BuildConfiguration], Root);
        await RunProcessAsync("dotnet", ["build", LicenseToolProject, "-c", BuildConfiguration], Root);
        await RunProcessAsync("dotnet", ["build", LicenseManagerProject, "-c", BuildConfiguration], Root);
        await TestAsync();
    }

    private static async Task PublishLicenseManagerAsync()
    {
        var output = Path.Combine(Root, "artifacts", "license-manager", "win-x64");
        DeleteDirectoryInsideWorkspace(output);
        Console.WriteLine("Publishing the POS Printer Emulator License Manager...");
        await RunProcessAsync(
            "dotnet",
            [
                "publish",
                LicenseManagerProject,
                "-c", BuildConfiguration,
                "-r", "win-x64",
                "--self-contained", "true",
                "-p:PublishSingleFile=true",
                "-p:IncludeNativeLibrariesForSelfExtract=true",
                "-p:DebugType=None",
                "-o", output
            ],
            Root);
        Console.WriteLine($"License Manager created at {output}");
    }

    private static async Task TestAsync()
    {
        Console.WriteLine("Running POS Printer Emulator tests...");
        await RunProcessAsync("dotnet", ["test", TestProject, "-c", BuildConfiguration], Root);
    }

    private static async Task PublishAsync()
    {
        await BuildAsync();
        DeleteDirectoryInsideWorkspace(PublishDirectory);
        DeleteDirectoryInsideWorkspace(UpdaterPublishDirectory);

        Console.WriteLine("Publishing the self-contained Windows application...");
        await RunProcessAsync(
            "dotnet",
            [
                "publish",
                AppProject,
                "-c", BuildConfiguration,
                "-r", "win-x64",
                "--self-contained", "true",
                "-p:PublishSingleFile=true",
                "-p:IncludeNativeLibrariesForSelfExtract=true",
                "-p:DebugType=None",
                "-o", PublishDirectory
            ],
            Root);

        await RunProcessAsync(
            "dotnet",
            [
                "publish",
                DesktopProject,
                "-c", BuildConfiguration,
                "-r", "win-x64",
                "--self-contained", "true",
                "-p:DebugType=None",
                "-o", PublishDirectory
            ],
            Root);

        await RunProcessAsync(
            "dotnet",
            [
                "publish",
                UpdaterProject,
                "-c", BuildConfiguration,
                "-r", "win-x64",
                "--self-contained", "true",
                "-p:PublishSingleFile=true",
                "-p:IncludeNativeLibrariesForSelfExtract=true",
                "-p:DebugType=None",
                "-o", UpdaterPublishDirectory
            ],
            Root);
        File.Copy(
            Path.Combine(UpdaterPublishDirectory, "POSPrinterEmulator.Updater.exe"),
            Path.Combine(PublishDirectory, "POSPrinterEmulator.Updater.exe"),
            overwrite: true);

        await VerifyPublishedApplicationsAsync();
        Console.WriteLine($"Self-contained Windows publish created at {PublishDirectory}");
    }

    private static async Task InstallerAsync(bool skipPublish)
    {
        if (!skipPublish)
        {
            await PublishAsync();
        }
        else
        {
            await VerifyPublishedApplicationsAsync();
        }

        await EnsureWebView2BootstrapperAsync();
        var compiler = FindInnoSetupCompiler();
        var installerDefinition = Path.Combine(Root, "installer", "ReceiptLab.iss");
        ValidateInstallerBranding(installerDefinition);
        Console.WriteLine("Compiling the POS Printer Emulator Windows installer...");
        await RunProcessAsync(compiler, [installerDefinition], Root);

        var installerDirectory = Path.Combine(Root, "artifacts", "installer");
        var installerPath = Path.Combine(
            installerDirectory,
            $"POSPrinterEmulatorSetup-{ReadProductVersion()}-win-x64.exe");
        if (!File.Exists(installerPath))
        {
            throw new InvalidOperationException($"The expected installer was not created: {installerPath}");
        }

        await using var installerStream = File.OpenRead(installerPath);
        var checksum = Convert.ToHexString(await SHA256.HashDataAsync(installerStream)).ToLowerInvariant();
        var checksumPath = installerPath + ".sha256";
        await File.WriteAllTextAsync(
            checksumPath,
            $"{checksum}  {Path.GetFileName(installerPath)}{Environment.NewLine}");

        Console.WriteLine($"Installer created at {installerPath}");
        Console.WriteLine($"SHA-256 checksum created at {checksumPath}");
    }

    private static void ValidateInstallerBranding(string installerDefinition)
    {
        var definition = File.ReadAllText(installerDefinition);
        var requiredDirectives = new[]
        {
            @"SetupIconFile=..\assets\branding\pos-printer-emulator.ico",
            @"WizardImageFile=..\assets\branding\pos-printer-emulator-installer-banner.png",
            @"WizardSmallImageFile=..\assets\branding\pos-printer-emulator-icon.png"
        };

        foreach (var directive in requiredDirectives)
        {
            if (!definition.Contains(directive, StringComparison.Ordinal))
            {
                throw new InvalidOperationException($"The installer is missing required product branding: {directive}");
            }
        }

        var brandingDirectory = Path.Combine(Root, "assets", "branding");
        foreach (var fileName in new[]
                 {
                     "pos-printer-emulator.ico",
                     "pos-printer-emulator-icon.png",
                     "pos-printer-emulator-installer-banner.png"
                 })
        {
            if (!File.Exists(Path.Combine(brandingDirectory, fileName)))
            {
                throw new FileNotFoundException($"The installer branding asset is missing: {fileName}");
            }
        }

        var bannerPath = Path.Combine(brandingDirectory, "pos-printer-emulator-installer-banner.png");
        var (bannerWidth, bannerHeight) = ReadPngDimensions(bannerPath);
        if (bannerWidth * 314 != bannerHeight * 164)
        {
            throw new InvalidOperationException(
                $"The installer wizard banner must use the Inno Setup 164:314 aspect ratio without stretching; " +
                $"found {bannerWidth}x{bannerHeight}.");
        }
    }

    private static (int Width, int Height) ReadPngDimensions(string path)
    {
        Span<byte> header = stackalloc byte[24];
        using var stream = File.OpenRead(path);
        if (stream.Read(header) != header.Length ||
            !header[..8].SequenceEqual(new byte[] { 137, 80, 78, 71, 13, 10, 26, 10 }))
        {
            throw new InvalidOperationException($"The installer branding asset is not a valid PNG: {path}");
        }

        var width = System.Buffers.Binary.BinaryPrimitives.ReadInt32BigEndian(header[16..20]);
        var height = System.Buffers.Binary.BinaryPrimitives.ReadInt32BigEndian(header[20..24]);
        return (width, height);
    }

    private static async Task VerifyPublishedApplicationsAsync()
    {
        var expectedVersion = ReadProductVersion();
        if (!Version.TryParse(expectedVersion, out var parsedExpectedVersion))
        {
            throw new InvalidOperationException($"ProductInfo.Version '{expectedVersion}' is invalid.");
        }

        var serviceExecutable = Path.Combine(PublishDirectory, "ReceiptEmulator.exe");
        var desktopExecutable = Path.Combine(PublishDirectory, "POSPrinterEmulator.Desktop.exe");
        var updaterExecutable = Path.Combine(PublishDirectory, "POSPrinterEmulator.Updater.exe");
        foreach (var executable in new[] { serviceExecutable, desktopExecutable, updaterExecutable })
        {
            if (!File.Exists(executable))
            {
                throw new InvalidOperationException("The published application is missing. Run the installer command without --skip-publish.");
            }

            var fileVersion = FileVersionInfo.GetVersionInfo(executable).FileVersion;
            if (!Version.TryParse(fileVersion, out var parsedVersion) ||
                parsedVersion.Major != parsedExpectedVersion.Major ||
                parsedVersion.Minor != parsedExpectedVersion.Minor ||
                parsedVersion.Build != parsedExpectedVersion.Build)
            {
                throw new InvalidOperationException(
                    $"{Path.GetFileName(executable)} is version {fileVersion ?? "unknown"}; expected {expectedVersion}. Republish before building the installer.");
            }
        }

        if (!File.Exists(Path.Combine(PublishDirectory, "THIRD-PARTY-NOTICES.txt")))
        {
            throw new InvalidOperationException("THIRD-PARTY-NOTICES.txt is missing from the publish output. Republish before building the installer.");
        }

        Console.WriteLine("Verifying the bundled SQLite runtime...");
        await RunProcessAsync(serviceExecutable, ["--verify-sqlite-runtime"], Root);
    }

    private static async Task EnsureWebView2BootstrapperAsync()
    {
        if (File.Exists(WebView2Bootstrapper) && new FileInfo(WebView2Bootstrapper).Length > 500_000)
        {
            return;
        }

        Directory.CreateDirectory(PrerequisitesDirectory);
        var temporaryPath = WebView2Bootstrapper + ".download";
        Console.WriteLine("Downloading the Microsoft WebView2 installer prerequisite...");
        using var client = new HttpClient { Timeout = TimeSpan.FromMinutes(5) };
        using var response = await client.GetAsync("https://go.microsoft.com/fwlink/p/?LinkId=2124703");
        response.EnsureSuccessStatusCode();
        await using (var output = File.Create(temporaryPath))
        {
            await response.Content.CopyToAsync(output);
        }

        File.Move(temporaryPath, WebView2Bootstrapper, overwrite: true);
    }

    private static async Task SendSampleAsync(string[] arguments)
    {
        var host = GetOption(arguments, "--host") ?? "127.0.0.1";
        var title = GetOption(arguments, "--title") ?? "TCP SAMPLE";
        var portText = GetOption(arguments, "--port") ?? "9100";
        if (!int.TryParse(portText, out var port) || port is < 1 or > 65535)
        {
            throw new ArgumentException("--port must be a number between 1 and 65535.");
        }

        using var client = new TcpClient();
        await client.ConnectAsync(host, port);
        await using var stream = client.GetStream();
        var payload = CreateSamplePayload(title, port);
        await stream.WriteAsync(payload);
        await stream.FlushAsync();
        Console.WriteLine($"Sent {payload.Length} ESC/POS bytes to {host}:{port}");
    }

    private static byte[] CreateSamplePayload(string title, int port)
    {
        var bytes = new List<byte>();
        bytes.AddRange([0x1B, 0x40, 0x1B, 0x61, 0x01, 0x1D, 0x21, 0x11]);
        bytes.AddRange(Encoding.ASCII.GetBytes($"{title}\n"));
        bytes.AddRange([0x1D, 0x21, 0x00, 0x1B, 0x61, 0x00]);
        bytes.AddRange(Encoding.ASCII.GetBytes($"Sent over RAW TCP/IP\nPort: {port}\n\n"));
        bytes.AddRange([0x1D, 0x56, 0x42, 0x18]);
        return bytes.ToArray();
    }

    private static string? GetOption(string[] arguments, string name)
    {
        for (var index = 0; index < arguments.Length; index++)
        {
            if (!string.Equals(arguments[index], name, StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            if (index + 1 >= arguments.Length)
            {
                throw new ArgumentException($"{name} requires a value.");
            }

            return arguments[index + 1];
        }

        return null;
    }

    private static void SynchronizeReleaseVersion(bool checkOnly)
    {
        var displayVersion = checkOnly ? ReadPublicReleaseVersion() : ReadProductVersion();
        var installerPath = Path.Combine(
            Root,
            "artifacts",
            "installer",
            $"POSPrinterEmulatorSetup-{displayVersion}-win-x64.exe");
        var installer = File.Exists(installerPath) ? new FileInfo(installerPath) : null;
        var changed = new List<string>();

        ValidateAdminDevSupportRelease(displayVersion);

        foreach (var websitePage in Directory.EnumerateFiles(
                     Path.Combine(Root, "website"),
                     "*.html",
                     SearchOption.TopDirectoryOnly))
        {
            SynchronizeFile(
                websitePage,
                text => SynchronizeWebsiteReleaseText(text, displayVersion, installer),
                checkOnly,
                changed);
        }

        if (checkOnly && changed.Count > 0)
        {
            throw new InvalidOperationException(
                $"Release {displayVersion} is not synchronized in: {string.Join(", ", changed)}. Run the sync-release command and commit the results.");
        }

        if (!checkOnly)
        {
            var manifestPath = Path.Combine(Root, "website", "release.json");
            File.WriteAllText(
                manifestPath,
                JsonSerializer.Serialize(new { currentVersion = displayVersion }, new JsonSerializerOptions { WriteIndented = true }) + Environment.NewLine);
        }

        Console.WriteLine(changed.Count == 0
            ? $"Website release details match application version {displayVersion}."
            : $"Synchronized release {displayVersion}: {string.Join(", ", changed)}");
    }

    private static string ReadProductVersion()
    {
        var productInfoPath = Path.Combine(Root, "src", "ReceiptEmulator.App", "ProductInfo.cs");
        var productInfo = File.ReadAllText(productInfoPath);
        var versionMatch = Regex.Match(
            productInfo,
            "public\\s+const\\s+string\\s+Version\\s*=\\s*\"(?<version>[0-9]+\\.[0-9]+\\.[0-9]+)\"");
        if (!versionMatch.Success)
        {
            throw new InvalidOperationException("The release version could not be read from ProductInfo.cs.");
        }

        return versionMatch.Groups["version"].Value;
    }

    private static void ValidateAdminDevSupportRelease(string displayVersion)
    {
        var devSupportPath = Path.Combine(Root, "admin-website", "dev-support.php");
        if (!File.Exists(devSupportPath))
        {
            throw new InvalidOperationException("admin-website/dev-support.php is missing.");
        }

        var devSupport = File.ReadAllText(devSupportPath);
        var escapedVersion = Regex.Escape(displayVersion);
        var releasedRow = new Regex(
            $@"\('v{escapedVersion}',\s*'v{escapedVersion}',\s*'Release',\s*'[^']+',\s*'Released'",
            RegexOptions.CultureInvariant);
        if (!releasedRow.IsMatch(devSupport))
        {
            throw new InvalidOperationException(
                $"Admin Dev Support is missing released v{displayVersion}. Add its released roadmap row before synchronizing or publishing the release.");
        }
    }

    private static string ReadPublicReleaseVersion()
    {
        var manifestPath = Path.Combine(Root, "website", "release.json");
        if (!File.Exists(manifestPath))
        {
            throw new InvalidOperationException("website/release.json is missing. It must identify the current public release.");
        }

        using var document = JsonDocument.Parse(File.ReadAllText(manifestPath));
        var version = document.RootElement.GetProperty("currentVersion").GetString();
        if (version is null || !Regex.IsMatch(version, "^[0-9]+\\.[0-9]+\\.[0-9]+$"))
        {
            throw new InvalidOperationException("website/release.json contains an invalid currentVersion.");
        }

        return version;
    }

    private static string SynchronizeWebsiteReleaseText(string text, string displayVersion, FileInfo? installer)
    {
        var updated = Regex.Replace(
            Regex.Replace(
                Regex.Replace(text, "POSPrinterEmulatorSetup-[0-9]+\\.[0-9]+\\.[0-9]+-win-x64\\.exe", $"POSPrinterEmulatorSetup-{displayVersion}-win-x64.exe"),
                "\"softwareVersion\"\\s*:\\s*\"[0-9]+\\.[0-9]+\\.[0-9]+\"",
                $"\"softwareVersion\": \"{displayVersion}\""),
            "Version [0-9]+\\.[0-9]+\\.[0-9]+",
            $"Version {displayVersion}");

        updated = Regex.Replace(
            updated,
            "(\"releaseNotes\"\\s*:\\s*\"https://github\\.com/enocperez-spec/POS-Printer-Emulator-ESC-POS/releases/tag/v)[0-9]+\\.[0-9]+\\.[0-9]+(\")",
            match => $"{match.Groups[1].Value}{displayVersion}{match.Groups[2].Value}");

        updated = Regex.Replace(
            updated,
            "((?:Current release|Release status):</strong>\\s*)v[0-9]+\\.[0-9]+\\.[0-9]+",
            match => $"{match.Groups[1].Value}v{displayVersion}",
            RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);

        if (installer is null)
        {
            return updated;
        }

        var releaseDate = installer.LastWriteTimeUtc.ToString("yyyy-MM-dd", System.Globalization.CultureInfo.InvariantCulture);
        updated = Regex.Replace(updated, "\"fileSize\"\\s*:\\s*\"[^\"]+\"", $"\"fileSize\": \"{installer.Length} bytes\"");
        updated = Regex.Replace(updated, "\"datePublished\"\\s*:\\s*\"[0-9]{4}-[0-9]{2}-[0-9]{2}\"", $"\"datePublished\": \"{releaseDate}\"");
        updated = Regex.Replace(updated, "\"dateModified\"\\s*:\\s*\"[0-9]{4}-[0-9]{2}-[0-9]{2}\"", $"\"dateModified\": \"{releaseDate}\"");
        return updated;
    }

    private static void SynchronizeFile(
        string path,
        Func<string, string> transform,
        bool checkOnly,
        ICollection<string> changed)
    {
        var current = File.ReadAllText(path);
        var updated = transform(current);
        if (string.Equals(current, updated, StringComparison.Ordinal))
        {
            return;
        }

        changed.Add(Path.GetRelativePath(Root, path));
        if (!checkOnly)
        {
            File.WriteAllText(path, updated, new UTF8Encoding(encoderShouldEmitUTF8Identifier: false));
        }
    }

    private static void CheckWebsiteSeo()
    {
        var websiteDirectory = Path.Combine(Root, "website");
        var sitemapPath = Path.Combine(websiteDirectory, "sitemap.xml");
        var failures = new List<string>();
        var sitemap = XDocument.Load(sitemapPath);
        var urls = sitemap.Descendants()
            .Where(element => element.Name.LocalName == "loc")
            .Select(element => element.Value.Trim())
            .ToArray();

        if (urls.Length == 0)
        {
            failures.Add("sitemap.xml contains no public URLs");
        }
        if (urls.Distinct(StringComparer.OrdinalIgnoreCase).Count() != urls.Length)
        {
            failures.Add("sitemap.xml contains duplicate URLs");
        }

        foreach (var publicUrl in urls)
        {
            if (!Uri.TryCreate(publicUrl, UriKind.Absolute, out var uri) ||
                !string.Equals(uri.Scheme, "https", StringComparison.OrdinalIgnoreCase) ||
                !string.Equals(uri.Host, "www.posprinteremulator.com", StringComparison.OrdinalIgnoreCase))
            {
                failures.Add($"sitemap URL is not canonical HTTPS/www: {publicUrl}");
                continue;
            }
            if (uri.AbsolutePath.EndsWith(".html", StringComparison.OrdinalIgnoreCase))
            {
                failures.Add($"sitemap URL contains .html: {publicUrl}");
            }

            var slug = uri.AbsolutePath.Trim('/');
            var fileName = slug.Length == 0 ? "index.html" : slug + ".html";
            var htmlPath = Path.Combine(websiteDirectory, fileName);
            if (!File.Exists(htmlPath))
            {
                failures.Add($"sitemap URL has no matching HTML file: {publicUrl}");
                continue;
            }

            var html = File.ReadAllText(htmlPath);
            var title = MatchContent(html, "<title>(?<content>.*?)</title>");
            var description = MatchContent(html, "<meta\\s+name=\"description\"\\s+content=\"(?<content>[^\"]+)\"");
            var canonical = MatchContent(html, "<link\\s+rel=\"canonical\"\\s+href=\"(?<content>[^\"]+)\"");
            var h1Count = Regex.Matches(html, "<h1(?:\\s[^>]*)?>", RegexOptions.IgnoreCase).Count;
            if (string.IsNullOrWhiteSpace(title)) failures.Add($"{fileName} is missing a title");
            if (string.IsNullOrWhiteSpace(description)) failures.Add($"{fileName} is missing a meta description");
            if (!string.Equals(canonical, publicUrl, StringComparison.OrdinalIgnoreCase)) failures.Add($"{fileName} canonical does not match its sitemap URL");
            if (h1Count != 1) failures.Add($"{fileName} must contain exactly one H1 (found {h1Count})");
            if (slug.Length > 0 && slug != "privacy" && !html.Contains("class=\"breadcrumbs\"", StringComparison.OrdinalIgnoreCase)) failures.Add($"{fileName} is missing visible breadcrumbs");

            foreach (Match jsonLd in Regex.Matches(html, "<script\\s+type=\"application/ld\\+json\"[^>]*>(?<json>.*?)</script>", RegexOptions.IgnoreCase | RegexOptions.Singleline))
            {
                try
                {
                    using var _ = JsonDocument.Parse(jsonLd.Groups["json"].Value);
                }
                catch (JsonException exception)
                {
                    failures.Add($"{fileName} contains invalid JSON-LD: {exception.Message}");
                }
            }
        }

        var homepage = File.ReadAllText(Path.Combine(websiteDirectory, "index.html"));
        foreach (var requiredSoftwareProperty in new[] { "SoftwareApplication", "softwareVersion", "downloadUrl", "screenshot", "featureList", "publisher", "offers", "fileSize", "datePublished", "releaseNotes", "softwareRequirements" })
        {
            if (!homepage.Contains(requiredSoftwareProperty, StringComparison.Ordinal))
            {
                failures.Add($"homepage SoftwareApplication data is missing {requiredSoftwareProperty}");
            }
        }

        var htaccess = File.ReadAllText(Path.Combine(websiteDirectory, ".htaccess"));
        if (!htaccess.Contains("https://www.posprinteremulator.com%{REQUEST_URI}", StringComparison.Ordinal) ||
            !htaccess.Contains("RewriteRule ^(.+)\\.html$", StringComparison.Ordinal))
        {
            failures.Add(".htaccess is missing canonical host or extensionless redirects");
        }

        CheckMaximumFileSize(Path.Combine(websiteDirectory, "assets", "favicon.png"), 10_000, failures);
        CheckMaximumFileSize(Path.Combine(websiteDirectory, "assets", "product-app.webp"), 150_000, failures);
        var indexNowKey = File.ReadAllText(Path.Combine(websiteDirectory, "indexnow-key.txt")).Trim();
        if (indexNowKey.Length is < 8 or > 128 || indexNowKey.Any(character => !Uri.IsHexDigit(character)))
        {
            failures.Add("indexnow-key.txt must contain an 8-128 character hexadecimal key");
        }

        var supportPolicyFiles = Directory.EnumerateFiles(websiteDirectory, "*.html", SearchOption.TopDirectoryOnly)
            .Concat([
                Path.Combine(Root, "README.md"),
                Path.Combine(Root, "src", "ReceiptEmulator.Viewer", "src", "App.tsx")
            ]);
        var obsoleteWindowsClaims = new[]
        {
            "Windows 10 64-bit",
            "Windows 10 and Windows 11",
            "Windows 10 or Windows 11",
            "Windows 10/11",
            "Windows 10 / 11",
            "Windows 10 or 11"
        };
        foreach (var policyFile in supportPolicyFiles)
        {
            var policyText = File.ReadAllText(policyFile);
            foreach (var obsoleteClaim in obsoleteWindowsClaims)
            {
                if (policyText.Contains(obsoleteClaim, StringComparison.OrdinalIgnoreCase))
                {
                    failures.Add($"{Path.GetFileName(policyFile)} contains obsolete support claim: {obsoleteClaim}");
                }
            }
        }

        if (failures.Count > 0)
        {
            throw new InvalidOperationException("SEO validation failed:" + Environment.NewLine + "- " + string.Join(Environment.NewLine + "- ", failures));
        }

        Console.WriteLine($"SEO validation passed for {urls.Length} canonical public pages.");
    }

    private static string? MatchContent(string input, string pattern)
    {
        var match = Regex.Match(input, pattern, RegexOptions.IgnoreCase | RegexOptions.Singleline);
        return match.Success ? System.Net.WebUtility.HtmlDecode(match.Groups["content"].Value.Trim()) : null;
    }

    private static void CheckMaximumFileSize(string path, long maximumBytes, ICollection<string> failures)
    {
        if (!File.Exists(path))
        {
            failures.Add($"required performance asset is missing: {Path.GetFileName(path)}");
            return;
        }
        var length = new FileInfo(path).Length;
        if (length > maximumBytes)
        {
            failures.Add($"{Path.GetFileName(path)} is {length:N0} bytes; maximum is {maximumBytes:N0}");
        }
    }

    private static async Task RunProcessAsync(string executable, IReadOnlyList<string> arguments, string workingDirectory)
    {
        var isCommandScript = OperatingSystem.IsWindows() &&
            (executable.EndsWith(".cmd", StringComparison.OrdinalIgnoreCase) ||
             executable.EndsWith(".bat", StringComparison.OrdinalIgnoreCase));

        var startInfo = new ProcessStartInfo
        {
            FileName = executable,
            WorkingDirectory = workingDirectory,
            UseShellExecute = isCommandScript
        };

        if (isCommandScript)
        {
            startInfo.Arguments = string.Join(" ", arguments.Select(QuoteArgument));
        }
        else
        {
            foreach (var argument in arguments)
            {
                startInfo.ArgumentList.Add(argument);
            }
        }

        using var process = new Process { StartInfo = startInfo };
        if (!process.Start())
        {
            throw new InvalidOperationException($"Unable to start {executable}.");
        }

        await process.WaitForExitAsync();
        if (process.ExitCode != 0)
        {
            throw new InvalidOperationException($"{Path.GetFileName(executable)} exited with code {process.ExitCode}.");
        }
    }

    private static string QuoteArgument(string argument)
    {
        if (argument.Length > 0 && argument.All(character => !char.IsWhiteSpace(character) && character != '"'))
        {
            return argument;
        }

        return $"\"{argument.Replace("\"", "\\\"")}\"";
    }

    private static string FindInnoSetupCompiler()
    {
        var candidates = new[]
        {
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Programs", "Inno Setup 6", "ISCC.exe"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), "Inno Setup 6", "ISCC.exe"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "Inno Setup 6", "ISCC.exe")
        };

        return candidates.FirstOrDefault(File.Exists)
            ?? FindCommand("ISCC.exe")
            ?? throw new InvalidOperationException(
                "Inno Setup 6 is required. Install it from https://jrsoftware.org/isdl.php and rerun the installer command.");
    }

    private static string FindRequiredCommand(params string[] names)
    {
        foreach (var name in names)
        {
            var command = FindCommand(name);
            if (command is not null)
            {
                return command;
            }
        }

        throw new InvalidOperationException($"Required command '{names[0]}' was not found on PATH.");
    }

    private static string? FindCommand(string name)
    {
        if (Path.IsPathRooted(name) && File.Exists(name))
        {
            return Path.GetFullPath(name);
        }

        var path = Environment.GetEnvironmentVariable("PATH") ?? string.Empty;
        foreach (var directory in path.Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            var candidate = Path.Combine(directory.Trim('"'), name);
            if (File.Exists(candidate))
            {
                return candidate;
            }
        }

        return null;
    }

    private static void DeleteDirectoryInsideWorkspace(string directory)
    {
        var rootPath = Path.GetFullPath(Root).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var targetPath = Path.GetFullPath(directory).TrimEnd(Path.DirectorySeparatorChar);
        if (!targetPath.StartsWith(rootPath, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException($"Refusing to delete a directory outside the POS Printer Emulator workspace: {targetPath}");
        }

        if (Directory.Exists(targetPath))
        {
            Directory.Delete(targetPath, recursive: true);
        }
    }

    private static string FindProjectRoot()
    {
        foreach (var start in new[] { Environment.CurrentDirectory, AppContext.BaseDirectory })
        {
            var directory = new DirectoryInfo(start);
            while (directory is not null)
            {
                if (File.Exists(Path.Combine(directory.FullName, "src", "ReceiptEmulator.App", "ReceiptEmulator.App.csproj")) &&
                    File.Exists(Path.Combine(directory.FullName, "installer", "ReceiptLab.iss")))
                {
                    return directory.FullName;
                }

                directory = directory.Parent;
            }
        }

        throw new DirectoryNotFoundException("The POS Printer Emulator project root could not be located.");
    }

    private static void PrintHelp()
    {
        Console.WriteLine("""
            POS Printer Emulator C# build utility

            Usage:
              dotnet run --project tools/ReceiptLab.Build -- <command> [options]

            Commands:
              build                         Build viewer/app and run tests
              test                          Run automated tests
              publish                       Create the self-contained win-x64 application
              installer                     Build the complete Windows installer
              installer --skip-publish      Repackage the existing publish output
              license-manager               Publish the vendor License Manager UI
              sync-release                  Promote ProductInfo.Version after verifying Admin Dev Support, then update public website metadata
              sync-release --check          Verify Admin Dev Support and website pages against website/release.json
              check-seo                     Validate canonical URLs, metadata, structured data, sitemap, and performance assets
              send-sample                   Send a sample ESC/POS job to localhost:9100
              send-sample --host HOST --port PORT --title TITLE
              help                          Show this help
            """);
    }
}
