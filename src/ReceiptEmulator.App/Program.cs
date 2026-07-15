using System.Text;
using ReceiptEmulator;

var setupExitCode = await WindowsSetupCommand.TryRunAsync(args);
if (setupExitCode is not null)
{
    Environment.ExitCode = setupExitCode.Value;
    return;
}

var builder = WebApplication.CreateBuilder(args);
var supportLogs = new SupportLogProvider();
builder.Logging.AddProvider(supportLogs);
builder.Host.UseWindowsService(options => options.ServiceName = "POS Printer Emulator");
builder.WebHost.UseUrls(builder.Configuration["Viewer:Url"] ?? "http://127.0.0.1:5187");

var printerOptions = builder.Configuration.GetSection("Printer").Get<PrinterOptions>() ?? new PrinterOptions();
builder.Services.AddSingleton(printerOptions);
builder.Services.AddSingleton<EscPosParser>();
builder.Services.AddSingleton<ReceiptStore>();
builder.Services.AddSingleton<LicenseService>();
builder.Services.AddSingleton<ReceiptProcessor>();
builder.Services.AddSingleton<ServiceRuntimeState>();
builder.Services.AddSingleton<PrinterStateService>();
builder.Services.AddSingleton<StoredGraphicService>();
builder.Services.AddSingleton(supportLogs);
builder.Services.AddHttpClient("UsageTelemetry");
builder.Services.AddSingleton<UsageTelemetryService>(services => new UsageTelemetryService(
    services.GetRequiredService<IHttpClientFactory>().CreateClient("UsageTelemetry"),
    services.GetRequiredService<LicenseService>(),
    services.GetRequiredService<IConfiguration>(),
    services.GetRequiredService<IHostEnvironment>(),
    services.GetRequiredService<ILogger<UsageTelemetryService>>()));
builder.Services.AddSingleton<IUsageTelemetry>(services => services.GetRequiredService<UsageTelemetryService>());
builder.Services.AddHostedService(services => services.GetRequiredService<UsageTelemetryService>());
builder.Services.AddHttpClient<UpdateService>(client =>
{
    client.BaseAddress = new Uri("https://api.github.com/repos/enocperez-spec/POS-Printer-Emulator-ESC-POS/");
    client.DefaultRequestHeaders.UserAgent.ParseAdd($"POS-Printer-Emulator/{ProductInfo.Version}");
    client.DefaultRequestHeaders.Accept.ParseAdd("application/vnd.github+json");
    client.DefaultRequestHeaders.Add("X-GitHub-Api-Version", "2022-11-28");
    client.Timeout = TimeSpan.FromSeconds(15);
});
builder.Services.AddHostedService<TcpReceiptListener>();
builder.Services.AddHostedService<PeriodicUpdateChecker>();

var app = builder.Build();
app.UseDefaultFiles();
app.UseStaticFiles();

app.MapGet("/api/status", (ServiceRuntimeState runtime, LicenseService license, PrinterOptions options) =>
    new ServiceStatus(
        runtime.Listening,
        $"{options.BindAddress}:{options.Port}",
        runtime.LastConnection,
        ProductInfo.Version,
        license.GetStatus()));

app.MapGet("/api/updates/check", async (bool? force, UpdateService updates, CancellationToken cancellationToken) =>
    Results.Ok(await updates.CheckAsync(force == true, cancellationToken)));

app.MapGet("/api/updates/status", (UpdateService updates) =>
    updates.GetCached() is { } status ? Results.Ok(status) : Results.NoContent());

app.MapGet("/api/printer-setup/status", () => Results.Ok(PrinterSetupManager.GetStatus()));

app.MapGet("/api/printer-state", (PrinterStateService printerState) => Results.Ok(printerState.GetStatus()));

app.MapPut("/api/printer-state", (PrinterStateUpdateRequest request, PrinterStateService printerState) =>
{
    try { return Results.Ok(printerState.Update(request)); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapPost("/api/printer-state/reset", (PrinterStateService printerState) => Results.Ok(printerState.Reset()));

app.MapGet("/api/stored-graphics", (StoredGraphicService graphics) => Results.Ok(graphics.List()));

app.MapGet("/api/stored-graphics/{keyCode}/content", (string keyCode, StoredGraphicService graphics) =>
    graphics.TryRead(keyCode, out var content, out var contentType)
        ? Results.File(content, contentType)
        : Results.NotFound());

app.MapPost("/api/stored-graphics/{keyCode}", async (string keyCode, HttpRequest request, StoredGraphicService graphics, CancellationToken cancellationToken) =>
{
    try
    {
        if (!request.HasFormContentType) return Results.Problem("Choose an image file to import.", statusCode: 400);
        var form = await request.ReadFormAsync(cancellationToken);
        var file = form.Files.GetFile("file");
        if (file is null || file.Length == 0) return Results.Problem("Choose an image file to import.", statusCode: 400);
        if (file.Length > StoredGraphicService.MaximumFileBytes) return Results.Problem("Logo files must be 2 MB or smaller.", statusCode: 400);
        await using var stream = file.OpenReadStream();
        return Results.Ok(await graphics.ImportAsync(keyCode, form["name"].FirstOrDefault(), file.FileName, stream, cancellationToken));
    }
    catch (ArgumentException exception)
    {
        return Results.Problem(exception.Message, statusCode: 400);
    }
});

app.MapDelete("/api/stored-graphics/{keyCode}", async (string keyCode, StoredGraphicService graphics, CancellationToken cancellationToken) =>
{
    try { return await graphics.DeleteAsync(keyCode, cancellationToken) ? Results.NoContent() : Results.NotFound(); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapGet("/api/support/diagnostics", (ServiceRuntimeState runtime, LicenseService license, PrinterOptions options,
    ReceiptStore store, SupportLogProvider logs, PrinterStateService printerState, StoredGraphicService graphics) =>
{
    var status = license.GetStatus();
    var report = new StringBuilder()
        .AppendLine("POS Printer Emulator Support Diagnostics")
        .AppendLine($"Generated: {DateTimeOffset.Now:O}")
        .AppendLine($"Application version: {ProductInfo.Version}")
        .AppendLine($"Operating system: {Environment.OSVersion}")
        .AppendLine($"Runtime: {Environment.Version}")
        .AppendLine($"64-bit process: {Environment.Is64BitProcess}")
        .AppendLine($"Listener: {options.BindAddress}:{options.Port}")
        .AppendLine($"Listening: {runtime.Listening}")
        .AppendLine($"Last connection: {runtime.LastConnection?.ToString("O") ?? "None"}")
        .AppendLine($"License mode: {status.Mode}")
        .AppendLine($"License ID: {status.LicenseId?.ToString() ?? "None"}")
        .AppendLine($"Receipt jobs currently listed: {store.GetSummaries().Count}")
        .AppendLine($"Stored printer logos: {graphics.List().Count}")
        .AppendLine($"Simulated printer state: {printerState.GetStatus().Summary}")
        .AppendLine($"Printer status responses sent: {printerState.GetStatus().ResponsesSent}")
        .AppendLine()
        .AppendLine("Application log")
        .AppendLine("---------------")
        .AppendLine(logs.ReadLog())
        .AppendLine()
        .AppendLine("Printer setup log")
        .AppendLine("-----------------")
        .Append(PrinterSetupManager.ReadLog())
        .ToString();
    return Results.File(Encoding.UTF8.GetBytes(report), "text/plain", $"POS-Printer-Emulator-Diagnostics-{DateTime.Now:yyyyMMdd-HHmmss}.txt");
});

app.MapPost("/api/license/activate", (ActivationRequest request, LicenseService license, ReceiptStore store, IUsageTelemetry telemetry) =>
{
    try
    {
        var status = license.Activate(request.CustomerName, request.EmailAddress, request.ActivationKey);
        store.EnableFullHistory();
        telemetry.RecordActivation();
        return Results.Ok(status);
    }
    catch (InvalidOperationException exception)
    {
        return Results.Problem(exception.Message, statusCode: 400);
    }
});

app.MapGet("/api/jobs", (ReceiptStore store) => store.GetSummaries());

app.MapGet("/api/jobs/{id:guid}", (Guid id, ReceiptStore store) =>
{
    var job = store.Get(id);
    return job is null
        ? Results.NotFound()
        : Results.Ok(new
        {
            job.Id,
            job.ReceivedAt,
            job.SourceIp,
            job.PayloadSize,
            job.Status,
            job.UnsupportedCount,
            job.Receipt.Lines,
            job.Receipt.Commands,
            job.Receipt.PlainText,
            Hex = Convert.ToHexString(job.RawPayload).Chunk(2).Select(chars => new string(chars)).Chunk(16).Select(row => string.Join(" ", row))
        });
});

app.MapDelete("/api/jobs/{id:guid}", (Guid id, ReceiptStore store) =>
    store.Delete(id) ? Results.NoContent() : Results.NotFound());

app.MapDelete("/api/jobs", (ReceiptStore store) =>
    Results.Ok(new { Removed = store.Clear() }));

app.MapPost("/api/sample", (ReceiptProcessor processor) =>
{
    var job = processor.Process(SampleReceipt.Create(), "127.0.0.1", out var rejection);
    return job is null ? Results.Problem(rejection, statusCode: 429) : Results.Ok(new { job.Id });
});

app.MapGet("/api/jobs/{id:guid}/raw", (Guid id, ReceiptStore store, LicenseService license) =>
{
    if (!license.IsFullVersion)
        return Results.Problem("Raw-data export is available in the Full Version.", statusCode: 403);
    var job = store.Get(id);
    return job is null ? Results.NotFound() : Results.File(job.RawPayload, "application/octet-stream", $"receipt-{id:N}.bin");
});

app.MapGet("/api/jobs/{id:guid}/text", (Guid id, ReceiptStore store, LicenseService license) =>
{
    if (!license.IsFullVersion)
        return Results.Problem("Text export is available in the Full Version.", statusCode: 403);
    var job = store.Get(id);
    return job is null
        ? Results.NotFound()
        : Results.File(Encoding.UTF8.GetBytes(job.Receipt.PlainText), "text/plain", $"receipt-{id:N}.txt");
});

app.MapFallbackToFile("index.html");
app.Run();

public partial class Program;
