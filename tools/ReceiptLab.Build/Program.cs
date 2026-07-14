using System.Diagnostics;
using System.Net.Sockets;
using System.Text;

return await ReceiptLabBuild.RunAsync(args);

internal static class ReceiptLabBuild
{
    private const string BuildConfiguration = "Release";
    private static readonly string Root = FindProjectRoot();
    private static readonly string AppProject = Path.Combine(Root, "src", "ReceiptEmulator.App", "ReceiptEmulator.App.csproj");
    private static readonly string DesktopProject = Path.Combine(Root, "src", "POSPrinterEmulator.Desktop", "POSPrinterEmulator.Desktop.csproj");
    private static readonly string TestProject = Path.Combine(Root, "tests", "ReceiptEmulator.Tests", "ReceiptEmulator.Tests.csproj");
    private static readonly string ViewerDirectory = Path.Combine(Root, "src", "ReceiptEmulator.Viewer");
    private static readonly string WebRoot = Path.Combine(Root, "src", "ReceiptEmulator.App", "wwwroot");
    private static readonly string PublishDirectory = Path.Combine(Root, "artifacts", "win-x64");
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
        await TestAsync();
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

        Console.WriteLine($"Self-contained Windows publish created at {PublishDirectory}");
    }

    private static async Task InstallerAsync(bool skipPublish)
    {
        if (!skipPublish)
        {
            await PublishAsync();
        }
        else if (!File.Exists(Path.Combine(PublishDirectory, "ReceiptEmulator.exe")) ||
                 !File.Exists(Path.Combine(PublishDirectory, "POSPrinterEmulator.Desktop.exe")))
        {
            throw new InvalidOperationException("The published application is missing. Run the installer command without --skip-publish.");
        }

        await EnsureWebView2BootstrapperAsync();
        var compiler = FindInnoSetupCompiler();
        var installerDefinition = Path.Combine(Root, "installer", "ReceiptLab.iss");
        Console.WriteLine("Compiling the POS Printer Emulator Windows installer...");
        await RunProcessAsync(compiler, [installerDefinition], Root);
        Console.WriteLine($"Installer created in {Path.Combine(Root, "artifacts", "installer")}");
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
              send-sample                   Send a sample ESC/POS job to localhost:9100
              send-sample --host HOST --port PORT --title TITLE
              help                          Show this help
            """);
    }
}
