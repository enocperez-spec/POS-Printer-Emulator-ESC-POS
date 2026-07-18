using System.Text;

namespace ReceiptEmulator;

public sealed class SupportLogProvider : ILoggerProvider
{
    private const long MaximumLogBytes = 2 * 1024 * 1024;
    private readonly object _sync = new();

    public SupportLogProvider()
    {
        var root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "POSPrinterEmulator", "Logs");
        try
        {
            Directory.CreateDirectory(root);
        }
        catch
        {
            root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator", "Logs");
            Directory.CreateDirectory(root);
        }

        LogPath = Path.Combine(root, "pos-printer-emulator.log");
    }

    public string LogPath { get; }

    public ILogger CreateLogger(string categoryName) => new SupportFileLogger(this, categoryName);

    public string ReadLog()
    {
        lock (_sync)
        {
            var builder = new StringBuilder();
            var previous = Path.ChangeExtension(LogPath, ".previous.log");
            if (File.Exists(previous)) builder.AppendLine(File.ReadAllText(previous));
            if (File.Exists(LogPath)) builder.Append(File.ReadAllText(LogPath));
            return builder.ToString();
        }
    }

    internal void Write(LogLevel level, string category, string message, Exception? exception)
    {
        if (level < LogLevel.Information) return;
        lock (_sync)
        {
            try
            {
                if (File.Exists(LogPath) && new FileInfo(LogPath).Length >= MaximumLogBytes)
                {
                    File.Move(LogPath, Path.ChangeExtension(LogPath, ".previous.log"), true);
                }

                var entry = $"{DateTimeOffset.Now:O} [{level}] {category}: {message}";
                if (exception is not null) entry += $"{Environment.NewLine}{exception}";
                File.AppendAllText(LogPath, entry + Environment.NewLine, Encoding.UTF8);
            }
            catch
            {
                // Logging must never stop the local printer service.
            }
        }
    }

    public void Dispose() { }

    private sealed class SupportFileLogger(SupportLogProvider provider, string category) : ILogger
    {
        public IDisposable? BeginScope<TState>(TState state) where TState : notnull => null;
        public bool IsEnabled(LogLevel logLevel) => logLevel >= LogLevel.Information;
        public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception,
            Func<TState, Exception?, string> formatter)
        {
            if (IsEnabled(logLevel)) provider.Write(logLevel, category, formatter(state, exception), exception);
        }
    }
}
