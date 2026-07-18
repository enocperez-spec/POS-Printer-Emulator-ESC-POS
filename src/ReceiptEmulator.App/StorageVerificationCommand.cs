using Microsoft.Data.Sqlite;

namespace ReceiptEmulator;

internal static class StorageVerificationCommand
{
    private const string Command = "--verify-sqlite-runtime";

    public static int? TryRun(string[] args)
    {
        if (args.Length != 1 || !string.Equals(args[0], Command, StringComparison.OrdinalIgnoreCase))
        {
            return null;
        }

        var root = Path.Combine(Path.GetTempPath(), "POSPrinterEmulator.StorageVerification", Guid.NewGuid().ToString("N"));
        try
        {
            var database = new ReceiptDatabase(root);
            if (!database.IntegrityCheck())
            {
                Console.Error.WriteLine("SQLite runtime verification failed its integrity check.");
                return 1;
            }

            Console.WriteLine("SQLite runtime verification passed.");
            return 0;
        }
        catch (Exception exception)
        {
            Console.Error.WriteLine($"SQLite runtime verification failed: {exception.Message}");
            return 1;
        }
        finally
        {
            SqliteConnection.ClearAllPools();
            try
            {
                if (Directory.Exists(root))
                {
                    Directory.Delete(root, recursive: true);
                }
            }
            catch
            {
                // The operating system can clear an abandoned temporary verification folder later.
            }
        }
    }
}
