using System.Text.Json;

namespace ReceiptEmulator;

public sealed class TrialGate
{
    private const int DailyLimit = 5;
    private readonly object _sync = new();
    private readonly string _statePath;
    private TrialState _state;

    public TrialGate(IHostEnvironment environment)
    {
        var root = environment.IsEnvironment("Testing")
            ? Path.Combine(Path.GetTempPath(), "ReceiptLab.Tests", Guid.NewGuid().ToString("N"))
            : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ReceiptLab");
        Directory.CreateDirectory(root);
        _statePath = Path.Combine(root, "trial-state.json");
        _state = Load();
    }

    public TrialStatus GetStatus()
    {
        lock (_sync)
        {
            RollDateForward();
            return new TrialStatus("Trial", DailyLimit, _state.Used, Math.Max(0, DailyLimit - _state.Used), _state.Date);
        }
    }

    public bool TryConsume(out TrialStatus status)
    {
        lock (_sync)
        {
            RollDateForward();
            if (_state.Used >= DailyLimit)
            {
                status = GetStatus();
                return false;
            }

            _state.Used++;
            Save();
            status = GetStatus();
            return true;
        }
    }

    private TrialState Load()
    {
        try
        {
            if (File.Exists(_statePath))
                return JsonSerializer.Deserialize<TrialState>(File.ReadAllText(_statePath)) ?? NewState();
        }
        catch
        {
            // A corrupt state never grants extra trial jobs; start the current day at the limit.
            return new TrialState(DateOnly.FromDateTime(DateTime.Now), DailyLimit);
        }

        return NewState();
    }

    private static TrialState NewState() => new(DateOnly.FromDateTime(DateTime.Now), 0);

    private void RollDateForward()
    {
        var today = DateOnly.FromDateTime(DateTime.Now);
        if (today > _state.Date)
        {
            _state = new TrialState(today, 0);
            Save();
        }
    }

    private void Save()
    {
        var temp = _statePath + ".tmp";
        File.WriteAllText(temp, JsonSerializer.Serialize(_state));
        File.Move(temp, _statePath, true);
    }

    private sealed class TrialState(DateOnly date, int used)
    {
        public DateOnly Date { get; set; } = date;
        public int Used { get; set; } = used;
    }
}
