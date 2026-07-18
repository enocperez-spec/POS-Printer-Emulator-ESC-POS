using System.Text;
using ReceiptEmulator;

var setupExitCode = await WindowsSetupCommand.TryRunAsync(args);
if (setupExitCode is not null)
{
    Environment.ExitCode = setupExitCode.Value;
    return;
}

var storageVerificationExitCode = StorageVerificationCommand.TryRun(args);
if (storageVerificationExitCode is not null)
{
    Environment.ExitCode = storageVerificationExitCode.Value;
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
builder.Services.AddSingleton<PrinterProfileService>();
builder.Services.AddSingleton<ReceiptProcessor>();
builder.Services.AddSingleton<CapturePackageService>();
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

app.MapGet("/api/updates/check", async (bool? force, UpdateService updates, LicenseService license, CancellationToken cancellationToken) =>
    !license.HasProAccess
        ? Results.Problem("Check for Updates requires a Pro or Enterprise License.", statusCode: 403)
        : Results.Ok(await updates.CheckAsync(force == true, cancellationToken)));

app.MapGet("/api/updates/status", (UpdateService updates, LicenseService license) =>
    !license.HasProAccess
        ? Results.Problem("Check for Updates requires a Pro or Enterprise License.", statusCode: 403)
        : updates.GetCached() is { } status ? Results.Ok(status) : Results.NoContent());

app.MapGet("/api/printer-setup/status", () => Results.Ok(PrinterSetupManager.GetStatus()));

app.MapGet("/api/printer-profiles", (PrinterProfileService profiles, LicenseService license) =>
    !license.HasProAccess
        ? Results.Problem("Printer Profiles requires a Pro or Enterprise License.", statusCode: 403)
        : Results.Ok(profiles.GetStatus()));

app.MapPost("/api/printer-profiles", (PrinterProfileInput request, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasProAccess) return Results.Problem("Printer Profiles requires a Pro or Enterprise License.", statusCode: 403);
    try { return Results.Ok(profiles.Create(request)); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapPut("/api/printer-profiles/{id}", (string id, PrinterProfileInput request, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasProAccess) return Results.Problem("Printer Profiles requires a Pro or Enterprise License.", statusCode: 403);
    try { return Results.Ok(profiles.Update(id, request)); }
    catch (KeyNotFoundException) { return Results.NotFound(); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapPost("/api/printer-profiles/{id}/duplicate", (string id, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasProAccess) return Results.Problem("Printer Profiles requires a Pro or Enterprise License.", statusCode: 403);
    try { return Results.Ok(profiles.Duplicate(id)); }
    catch (KeyNotFoundException) { return Results.NotFound(); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapPost("/api/printer-profiles/select", (PrinterProfileSelection request, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasProAccess) return Results.Problem("Printer Profiles requires a Pro or Enterprise License.", statusCode: 403);
    try { return Results.Ok(profiles.Select(request.ProfileId)); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapDelete("/api/printer-profiles/{id}", (string id, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasProAccess) return Results.Problem("Printer Profiles requires a Pro or Enterprise License.", statusCode: 403);
    try { return profiles.Delete(id) ? Results.NoContent() : Results.NotFound(); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapGet("/api/printer-profiles/{id}/export", (string id, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasProAccess) return Results.Problem("Printer Profiles requires a Pro or Enterprise License.", statusCode: 403);
    try { return Results.File(profiles.Export(id), "application/vnd.pos-printer-emulator.profile+json", $"{id}{PrinterProfileService.FileExtension}"); }
    catch (KeyNotFoundException) { return Results.NotFound(); }
});

app.MapPost("/api/printer-profiles/import", async (HttpRequest request, PrinterProfileService profiles, LicenseService license, CancellationToken cancellationToken) =>
{
    if (!license.HasProAccess) return Results.Problem("Printer Profiles requires a Pro or Enterprise License.", statusCode: 403);
    try
    {
        if (!request.HasFormContentType) return Results.Problem($"Choose a {PrinterProfileService.FileExtension} profile file.", statusCode: 400);
        var form = await request.ReadFormAsync(cancellationToken);
        var file = form.Files.GetFile("file");
        if (file is null || file.Length == 0) return Results.Problem($"Choose a {PrinterProfileService.FileExtension} profile file.", statusCode: 400);
        if (file.Length > PrinterProfileService.MaximumImportBytes) return Results.Problem("Printer profile files must be 128 KB or smaller.", statusCode: 400);
        await using var stream = file.OpenReadStream();
        return Results.Ok(await profiles.ImportAsync(stream, cancellationToken));
    }
    catch (InvalidDataException exception) { return Results.Problem(exception.Message, statusCode: 400); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapGet("/api/printer-state", (PrinterStateService printerState, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasProAccess) return Results.Problem("Printer State requires a Pro or Enterprise License.", statusCode: 403);
    var capabilities = profiles.GetSelected().Capabilities;
    return Results.Ok(printerState.GetStatus() with { DleEotSupported = capabilities.DleEotStatus, AsbSupported = capabilities.AutomaticStatusBack });
});

app.MapPut("/api/printer-state", (PrinterStateUpdateRequest request, PrinterStateService printerState, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasProAccess) return Results.Problem("Printer State requires a Pro or Enterprise License.", statusCode: 403);
    try
    {
        var capabilities = profiles.GetSelected().Capabilities;
        return Results.Ok(printerState.Update(request) with { DleEotSupported = capabilities.DleEotStatus, AsbSupported = capabilities.AutomaticStatusBack });
    }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapPost("/api/printer-state/reset", (PrinterStateService printerState, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasProAccess) return Results.Problem("Printer State requires a Pro or Enterprise License.", statusCode: 403);
    var capabilities = profiles.GetSelected().Capabilities;
    return Results.Ok(printerState.Reset() with { DleEotSupported = capabilities.DleEotStatus, AsbSupported = capabilities.AutomaticStatusBack });
});

app.MapGet("/api/stored-graphics", (StoredGraphicService graphics, LicenseService license) =>
    !license.HasProAccess
        ? Results.Problem("Stored Logos requires a Pro or Enterprise License.", statusCode: 403)
        : Results.Ok(graphics.List()));

app.MapGet("/api/stored-graphics/{keyCode}/content", (string keyCode, StoredGraphicService graphics, LicenseService license) =>
    !license.HasProAccess
        ? Results.Problem("Stored Logos requires a Pro or Enterprise License.", statusCode: 403)
        : graphics.TryRead(keyCode, out var content, out var contentType)
        ? Results.File(content, contentType)
        : Results.NotFound());

app.MapPost("/api/stored-graphics/{keyCode}", async (string keyCode, HttpRequest request, StoredGraphicService graphics, LicenseService license, CancellationToken cancellationToken) =>
{
    if (!license.HasProAccess) return Results.Problem("Stored Logos requires a Pro or Enterprise License.", statusCode: 403);
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

app.MapDelete("/api/stored-graphics/{keyCode}", async (string keyCode, StoredGraphicService graphics, LicenseService license, CancellationToken cancellationToken) =>
{
    if (!license.HasProAccess) return Results.Problem("Stored Logos requires a Pro or Enterprise License.", statusCode: 403);
    try { return await graphics.DeleteAsync(keyCode, cancellationToken) ? Results.NoContent() : Results.NotFound(); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapGet("/api/support/diagnostics", (ServiceRuntimeState runtime, LicenseService license, PrinterOptions options,
    ReceiptStore store, SupportLogProvider logs, PrinterStateService printerState, StoredGraphicService graphics, PrinterProfileService profiles) =>
{
    if (!license.HasProAccess) return Results.Problem("Support requires a Pro or Enterprise License.", statusCode: 403);
    var status = license.GetStatus();
    var selectedProfile = profiles.GetSelected();
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
        .AppendLine($"Printer profile: {selectedProfile.Name} ({selectedProfile.Id})")
        .AppendLine($"Profile paper: {selectedProfile.PaperWidthMm} mm / {selectedProfile.PrintableDots} dots")
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
        store.EnableProHistory();
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
            job.Origin,
            job.RendererVersion,
            job.OriginalReceivedAt,
            job.OriginalSourceIp,
            job.ParentJobId,
            job.ImportedFileName,
            job.ProfileId,
            job.ProfileName,
            job.ProfilePaperWidthMm,
            job.ProfilePrintableDots,
            job.CapturedProfileId,
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

app.MapPost("/api/captures/import", async (
    HttpRequest request,
    LicenseService license,
    CapturePackageService captures,
    ReceiptProcessor processor,
    PrinterOptions options,
    CancellationToken cancellationToken) =>
{
    if (!license.HasProAccess)
        return Results.Problem("Capture import requires a Pro or Enterprise License.", statusCode: 403);
    try
    {
        if (!request.HasFormContentType)
            return Results.Problem("Choose a .bin or .ppecapture receipt file to import.", statusCode: 400);
        var form = await request.ReadFormAsync(cancellationToken);
        var file = form.Files.GetFile("file");
        if (file is null || file.Length == 0)
            return Results.Problem("Choose a .bin or .ppecapture receipt file to import.", statusCode: 400);
        if (file.Length > options.MaximumJobBytes + CapturePackageService.PackageOverheadLimit)
            return Results.Problem("The receipt capture is larger than the configured limit.", statusCode: 400);
        await using var stream = file.OpenReadStream();
        var imported = await captures.ImportAsync(stream, file.FileName, options.MaximumJobBytes, cancellationToken);
        var job = processor.Import(
            imported.Payload,
            imported.FileName,
            imported.OriginalReceivedAt,
            imported.OriginalSourceIp,
            imported.CapturedJobId,
            imported.CapturedProfileId,
            out var rejection);
        return job is null
            ? Results.Problem(rejection, statusCode: 400)
            : Results.Ok(new { job.Id, job.Origin });
    }
    catch (InvalidDataException exception)
    {
        return Results.Problem(exception.Message, statusCode: 400);
    }
    catch (FormatException)
    {
        return Results.Problem("The capture package integrity value is invalid.", statusCode: 400);
    }
});

app.MapPost("/api/jobs/{id:guid}/replay", (Guid id, ReceiptStore store, ReceiptProcessor processor, LicenseService license) =>
{
    if (!license.HasProAccess)
        return Results.Problem("Receipt replay requires a Pro or Enterprise License.", statusCode: 403);
    var source = store.Get(id);
    if (source is null) return Results.NotFound();
    var replayed = processor.Replay(source, out var rejection);
    return replayed is null
        ? Results.Problem(rejection, statusCode: 400)
        : Results.Ok(new { replayed.Id, replayed.Origin });
});

app.MapGet("/api/jobs/{id:guid}/capture", (Guid id, ReceiptStore store, CapturePackageService captures, LicenseService license) =>
{
    if (!license.HasProAccess)
        return Results.Problem("Capture-package export requires a Pro or Enterprise License.", statusCode: 403);
    var job = store.Get(id);
    return job is null
        ? Results.NotFound()
        : Results.File(
            captures.Export(job),
            "application/vnd.pos-printer-emulator.capture+zip",
            $"receipt-{id:N}{CapturePackageService.FileExtension}");
});

app.MapGet("/api/jobs/{id:guid}/raw", (Guid id, ReceiptStore store, LicenseService license) =>
{
    if (!license.HasProAccess)
        return Results.Problem("Raw-data export requires a Pro or Enterprise License.", statusCode: 403);
    var job = store.Get(id);
    return job is null ? Results.NotFound() : Results.File(job.RawPayload, "application/octet-stream", $"receipt-{id:N}.bin");
});

app.MapGet("/api/jobs/{id:guid}/text", (Guid id, ReceiptStore store, LicenseService license) =>
{
    if (!license.HasProAccess)
        return Results.Problem("Text export requires a Pro or Enterprise License.", statusCode: 403);
    var job = store.Get(id);
    return job is null
        ? Results.NotFound()
        : Results.File(Encoding.UTF8.GetBytes(job.Receipt.PlainText), "text/plain", $"receipt-{id:N}.txt");
});

app.MapFallbackToFile("index.html");
app.Run();

public partial class Program;
