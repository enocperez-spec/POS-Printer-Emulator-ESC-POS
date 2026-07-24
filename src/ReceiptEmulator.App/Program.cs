using System.Text;
using Microsoft.AspNetCore.Http.Features;
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
builder.Services.AddSingleton<PrinterListenerConfigurationService>();
builder.Services.AddSingleton<PrinterListenerManager>();
builder.Services.AddSingleton<StoredGraphicService>();
builder.Services.AddSingleton<ConnectionDiagnosticsService>();
builder.Services.AddSingleton<ConfigurationBackupService>();
builder.Services.AddSingleton(supportLogs);
builder.Services.Configure<FormOptions>(options =>
    options.MultipartBodyLengthLimit = BackupPackageCodec.MaximumPackageBytes + 1024 * 1024);
builder.Services.AddHttpClient("UsageTelemetry");
builder.Services.AddSingleton<UsageTelemetryService>(services => new UsageTelemetryService(
    services.GetRequiredService<IHttpClientFactory>().CreateClient("UsageTelemetry"),
    services.GetRequiredService<LicenseService>(),
    services.GetRequiredService<IConfiguration>(),
    services.GetRequiredService<IHostEnvironment>(),
    services.GetRequiredService<ILogger<UsageTelemetryService>>()));
builder.Services.AddSingleton<IUsageTelemetry>(services => services.GetRequiredService<UsageTelemetryService>());
builder.Services.AddSingleton<IInstallationCredentialsProvider>(services => services.GetRequiredService<UsageTelemetryService>());
builder.Services.AddHostedService(services => services.GetRequiredService<UsageTelemetryService>());
builder.Services.AddHttpClient<PromotionAccessService>(client =>
{
    client.BaseAddress = new Uri("https://admin.posprinteremulator.com/");
    client.DefaultRequestHeaders.UserAgent.ParseAdd($"POS-Printer-Emulator/{ProductInfo.Version}");
    client.Timeout = TimeSpan.FromSeconds(20);
});
builder.Services.AddHttpClient<UpdateService>(client =>
{
    client.BaseAddress = new Uri("https://api.github.com/repos/enocperez-spec/POS-Printer-Emulator-ESC-POS/");
    client.DefaultRequestHeaders.UserAgent.ParseAdd($"POS-Printer-Emulator/{ProductInfo.Version}");
    client.DefaultRequestHeaders.Accept.ParseAdd("application/vnd.github+json");
    client.DefaultRequestHeaders.Add("X-GitHub-Api-Version", "2022-11-28");
    client.Timeout = TimeSpan.FromSeconds(15);
});
builder.Services.AddHttpClient<MaintenanceRefreshService>(client =>
{
    client.BaseAddress = new Uri("https://admin.posprinteremulator.com/");
    client.DefaultRequestHeaders.UserAgent.ParseAdd($"POS-Printer-Emulator/{ProductInfo.Version}");
    client.Timeout = TimeSpan.FromSeconds(15);
});
builder.Services.AddHttpClient<SupportRequestService>(client =>
{
    client.BaseAddress = new Uri("https://admin.posprinteremulator.com/");
    client.DefaultRequestHeaders.UserAgent.ParseAdd($"POS-Printer-Emulator/{ProductInfo.Version}");
    client.Timeout = TimeSpan.FromSeconds(30);
});
builder.Services.AddHostedService(services => services.GetRequiredService<PrinterListenerManager>());
builder.Services.AddHostedService<PeriodicUpdateChecker>();

var app = builder.Build();
app.UseDefaultFiles();
app.UseStaticFiles();

app.MapGet("/api/status", (PrinterListenerManager listeners, LicenseService license) =>
{
    var status = listeners.GetStatus();
    var defaultListener = status.Listeners.FirstOrDefault(listener =>
        listener.Configuration.Id.Equals(PrinterListenerDefaults.DefaultId, StringComparison.OrdinalIgnoreCase));
    return new ServiceStatus(
        status.ListeningCount > 0,
        defaultListener is null
            ? $"{PrinterListenerDefaults.DefaultBindAddress}:{PrinterListenerDefaults.DefaultPort}"
            : $"{defaultListener.Configuration.BindAddress}:{defaultListener.Configuration.Port}",
        status.Listeners.Select(listener => listener.LastConnection).Where(value => value is not null).Max(),
        ProductInfo.Version,
        license.GetStatus(),
        status.ToSummary());
});

app.MapGet("/api/updates/check", async (bool? force, UpdateService updates, LicenseService license, CancellationToken cancellationToken) =>
    !license.HasMaintenanceAccess
        ? Results.Problem("Check for Updates requires active Application Maintenance and Support coverage.", statusCode: 403)
        : Results.Ok(await updates.CheckAsync(force == true, cancellationToken)));

app.MapGet("/api/updates/status", (UpdateService updates, LicenseService license) =>
    !license.HasMaintenanceAccess
        ? Results.Problem("Check for Updates requires active Application Maintenance and Support coverage.", statusCode: 403)
        : updates.GetCached() is { } status ? Results.Ok(status) : Results.NoContent());

app.MapPost("/api/updates/prepare", async (
    ConfigurationBackupService backups,
    PrinterListenerManager listeners,
    LicenseService license,
    CancellationToken cancellationToken) =>
{
    if (!license.HasMaintenanceAccess)
        return Results.Problem("Installing updates requires active Application Maintenance and Support coverage.", statusCode: 403);
    try
    {
        var snapshotId = await backups.CreateSafetySnapshotAsync(cancellationToken);
        await listeners.PrepareForUpdateAsync(TimeSpan.FromSeconds(20), cancellationToken);
        return Results.Ok(new { prepared = true, safetySnapshotId = snapshotId });
    }
    catch (InvalidOperationException exception)
    {
        return Results.Problem(exception.Message, statusCode: 409);
    }
});

app.MapPost("/api/updates/resume", async (PrinterListenerManager listeners, CancellationToken cancellationToken) =>
{
    await listeners.ResumeAfterUpdatePreparationAsync(cancellationToken);
    return Results.Ok(new { resumed = true });
});

app.MapGet("/api/printer-setup/status", () => Results.Ok(PrinterSetupManager.GetStatus()));

app.MapGet("/api/printer-setup/available-port", (
    string printerName,
    string ipAddress,
    int? startingPort,
    PrinterListenerManager listeners,
    LicenseService license) =>
{
    try
    {
        var listenerStatus = listeners.GetStatus().Listeners;
        var currentListeners = listenerStatus.Select(listener => listener.Configuration).ToArray();
        var selection = PrinterSetupManager.GetAvailablePort(
            printerName,
            ipAddress,
            startingPort ?? PrinterListenerDefaults.DefaultPort,
            currentListeners,
            listenerStatus.Where(listener => listener.State == PrinterListenerRuntimeStates.Faulted)
                .Select(listener => listener.Configuration.Port));
        var reusesListener = currentListeners.Any(listener =>
            listener.Port == selection.Port &&
            PrinterSetupManager.IsListenerCompatibleForPrinterSetup(listener, ipAddress));
        var canMoveSingleDefault = license.MaximumListeners == 1 &&
                                   currentListeners.Length == 1 &&
                                   currentListeners[0].Id.Equals(PrinterListenerDefaults.DefaultId, StringComparison.OrdinalIgnoreCase);
        if (!reusesListener && currentListeners.Length >= license.MaximumListeners && !canMoveSingleDefault)
        {
            return Results.Problem(
                $"{selection.Message} " +
                $"The {license.GetStatus().Mode} License supports up to {license.MaximumListeners} printer listener{(license.MaximumListeners == 1 ? string.Empty : "s")}. Upgrade the license or reuse an existing compatible listener.",
                statusCode: 403);
        }
        return Results.Ok(selection);
    }
    catch (Exception exception) when (exception is ArgumentException or InvalidOperationException)
    {
        return Results.Problem(exception.Message, statusCode: 400);
    }
});

app.MapPost("/api/printer-setup/default-listener", async (
    SingleListenerSetupRequest request,
    PrinterListenerManager listeners,
    PrinterProfileService profiles,
    LicenseService license,
    CancellationToken cancellationToken) =>
{
    if (license.MaximumListeners != 1)
        return Results.Problem("Use Printer Listeners to configure this license.", statusCode: 400);
    if (request.Port is < PrinterListenerDefaults.DefaultPort or > 65535)
        return Results.Problem("The Trial printer port must be 9100 or higher.", statusCode: 400);
    try
    {
        return Results.Ok((await listeners.ConfigureSingleListenerForSetupAsync(request.Port, cancellationToken)).ToResponse(profiles));
    }
    catch (Exception exception)
    {
        return ListenerProblem(exception);
    }
});

app.MapGet("/api/listeners", (PrinterListenerManager listeners, PrinterProfileService profiles, LicenseService license) =>
{
    var response = listeners.GetStatus().Listeners.Select(listener => listener.ToResponse(profiles)).ToArray();
    return Results.Ok(new PrinterListenerCollectionResponse(response, license.MaximumListeners));
});

app.MapPost("/api/listeners", async (
    PrinterListenerInput request,
    PrinterListenerManager listeners,
    PrinterProfileService profiles,
    LicenseService license,
    CancellationToken cancellationToken) =>
{
    if (!license.CanManageMultipleListeners)
        return Results.Problem("Multiple printer listeners require a Pro or Enterprise License.", statusCode: 403);
    try { return Results.Ok((await listeners.CreateAsync(request, cancellationToken)).ToResponse(profiles)); }
    catch (Exception exception) { return ListenerProblem(exception); }
});

app.MapPut("/api/listeners/{id}", async (
    string id,
    PrinterListenerInput request,
    PrinterListenerManager listeners,
    PrinterProfileService profiles,
    LicenseService license,
    CancellationToken cancellationToken) =>
{
    if (!license.CanManageMultipleListeners)
        return Results.Problem("Multiple printer listeners require a Pro or Enterprise License.", statusCode: 403);
    try
    {
        var updated = await listeners.UpdateAsync(id, request, cancellationToken);
        if (id.Equals(PrinterListenerDefaults.DefaultId, StringComparison.OrdinalIgnoreCase))
            profiles.Select(request.ProfileId);
        return Results.Ok(updated.ToResponse(profiles));
    }
    catch (Exception exception) { return ListenerProblem(exception); }
});

app.MapDelete("/api/listeners/{id}", async (
    string id,
    PrinterListenerManager listeners,
    LicenseService license,
    CancellationToken cancellationToken) =>
{
    if (!license.CanManageMultipleListeners)
        return Results.Problem("Multiple printer listeners require a Pro or Enterprise License.", statusCode: 403);
    try
    {
        await listeners.DeleteAsync(id, cancellationToken);
        return Results.NoContent();
    }
    catch (Exception exception) { return ListenerProblem(exception); }
});

app.MapPost("/api/listeners/{id}/start", async (
    string id,
    PrinterListenerManager listeners,
    PrinterProfileService profiles,
    LicenseService license,
    CancellationToken cancellationToken) =>
{
    if (!license.CanManageMultipleListeners)
        return Results.Problem("Multiple printer listeners require a Pro or Enterprise License.", statusCode: 403);
    try { return Results.Ok((await listeners.StartListenerAsync(id, cancellationToken)).ToResponse(profiles)); }
    catch (Exception exception) { return ListenerProblem(exception); }
});

app.MapPost("/api/listeners/{id}/stop", async (
    string id,
    PrinterListenerManager listeners,
    PrinterProfileService profiles,
    LicenseService license,
    CancellationToken cancellationToken) =>
{
    if (!license.CanManageMultipleListeners)
        return Results.Problem("Multiple printer listeners require a Pro or Enterprise License.", statusCode: 403);
    try { return Results.Ok((await listeners.StopListenerAsync(id, cancellationToken)).ToResponse(profiles)); }
    catch (Exception exception) { return ListenerProblem(exception); }
});

app.MapPost("/api/listeners/{id}/restart", async (
    string id,
    PrinterListenerManager listeners,
    PrinterProfileService profiles,
    LicenseService license,
    CancellationToken cancellationToken) =>
{
    if (!license.CanManageMultipleListeners)
        return Results.Problem("Multiple printer listeners require a Pro or Enterprise License.", statusCode: 403);
    try { return Results.Ok((await listeners.RestartListenerAsync(id, cancellationToken)).ToResponse(profiles)); }
    catch (Exception exception) { return ListenerProblem(exception); }
});

app.MapGet("/api/printer-profiles", (PrinterProfileService profiles, LicenseService license) =>
    !license.HasPaidAccess
        ? Results.Problem("Printer Profiles requires a Lite, Pro, or Enterprise License.", statusCode: 403)
        : Results.Ok(profiles.GetStatus()));

app.MapPost("/api/printer-profiles", (PrinterProfileInput request, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasPaidAccess) return Results.Problem("Printer Profiles requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    try { return Results.Ok(profiles.Create(request)); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapPut("/api/printer-profiles/{id}", (string id, PrinterProfileInput request, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasPaidAccess) return Results.Problem("Printer Profiles requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    try { return Results.Ok(profiles.Update(id, request)); }
    catch (KeyNotFoundException) { return Results.NotFound(); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapPost("/api/printer-profiles/{id}/duplicate", (string id, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasPaidAccess) return Results.Problem("Printer Profiles requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    try { return Results.Ok(profiles.Duplicate(id)); }
    catch (KeyNotFoundException) { return Results.NotFound(); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapPost("/api/printer-profiles/select", async (
    PrinterProfileSelection request,
    PrinterProfileService profiles,
    PrinterListenerManager listeners,
    LicenseService license,
    CancellationToken cancellationToken) =>
{
    if (!license.HasPaidAccess) return Results.Problem("Printer Profiles requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    try
    {
        var previous = profiles.GetSelected();
        var selected = profiles.Select(request.ProfileId);
        try
        {
            if (license.CanManageMultipleListeners)
            {
                var current = listeners.Get(PrinterListenerDefaults.DefaultId).Configuration;
                var input = new PrinterListenerInput(
                    current.Name,
                    current.BindAddress,
                    current.Port,
                    selected.Id,
                    current.Enabled,
                    current.IdleJobTimeoutMilliseconds,
                    current.MaximumJobBytes,
                    current.Buffer);
                await listeners.UpdateAsync(current.Id, input, cancellationToken);
            }
            else
            {
                await listeners.ReconcileAsync(cancellationToken);
            }
        }
        catch
        {
            profiles.Select(previous.Id);
            throw;
        }
        return Results.Ok(selected);
    }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
    catch (InvalidOperationException exception) { return Results.Problem(exception.Message, statusCode: 409); }
});

app.MapDelete("/api/printer-profiles/{id}", (
    string id,
    PrinterProfileService profiles,
    PrinterListenerConfigurationService listeners,
    LicenseService license) =>
{
    if (!license.HasPaidAccess) return Results.Problem("Printer Profiles requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    if (listeners.IsProfileInUse(id))
        return Results.Problem("This printer profile is assigned to a configured listener. Reassign that listener before deleting the profile.", statusCode: 409);
    try { return profiles.Delete(id) ? Results.NoContent() : Results.NotFound(); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapGet("/api/printer-profiles/{id}/export", (string id, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasPaidAccess) return Results.Problem("Printer Profiles requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    try { return Results.File(profiles.Export(id), "application/vnd.pos-printer-emulator.profile+json", $"{id}{PrinterProfileService.FileExtension}"); }
    catch (KeyNotFoundException) { return Results.NotFound(); }
});

app.MapPost("/api/printer-profiles/import", async (HttpRequest request, PrinterProfileService profiles, LicenseService license, CancellationToken cancellationToken) =>
{
    if (!license.HasPaidAccess) return Results.Problem("Printer Profiles requires a Lite, Pro, or Enterprise License.", statusCode: 403);
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

app.MapGet("/api/printer-state", (PrinterListenerManager listeners, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasPaidAccess) return Results.Problem("Printer State requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    var capabilities = profiles.GetSelected().Capabilities;
    try
    {
        return Results.Ok(listeners.GetPrinterState(PrinterListenerDefaults.DefaultId) with
        {
            DleEotSupported = capabilities.DleEotStatus,
            AsbSupported = capabilities.AutomaticStatusBack
        });
    }
    catch (KeyNotFoundException) { return Results.Problem("The default printer listener is not ready yet.", statusCode: 503); }
});

app.MapPut("/api/printer-state", (PrinterStateUpdateRequest request, PrinterListenerManager listeners, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasPaidAccess) return Results.Problem("Printer State requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    try
    {
        var capabilities = profiles.GetSelected().Capabilities;
        return Results.Ok(listeners.UpdatePrinterState(PrinterListenerDefaults.DefaultId, request) with
        {
            DleEotSupported = capabilities.DleEotStatus,
            AsbSupported = capabilities.AutomaticStatusBack
        });
    }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
    catch (KeyNotFoundException) { return Results.Problem("The default printer listener is not ready yet.", statusCode: 503); }
});

app.MapPost("/api/printer-state/reset", (PrinterListenerManager listeners, PrinterProfileService profiles, LicenseService license) =>
{
    if (!license.HasPaidAccess) return Results.Problem("Printer State requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    var capabilities = profiles.GetSelected().Capabilities;
    try
    {
        return Results.Ok(listeners.ResetPrinterState(PrinterListenerDefaults.DefaultId) with
        {
            DleEotSupported = capabilities.DleEotStatus,
            AsbSupported = capabilities.AutomaticStatusBack
        });
    }
    catch (KeyNotFoundException) { return Results.Problem("The default printer listener is not ready yet.", statusCode: 503); }
});

app.MapGet("/api/listeners/{id}/printer-state", (
    string id,
    PrinterListenerManager listeners,
    PrinterProfileService profiles,
    LicenseService license) =>
{
    if (!license.CanManageMultipleListeners)
        return Results.Problem("Per-listener printer state requires a Pro or Enterprise License.", statusCode: 403);
    try
    {
        var listener = listeners.Get(id);
        var profile = profiles.Get(listener.Configuration.ProfileId);
        return Results.Ok(listeners.GetPrinterState(id) with
        {
            DleEotSupported = profile.Capabilities.DleEotStatus,
            AsbSupported = profile.Capabilities.AutomaticStatusBack
        });
    }
    catch (KeyNotFoundException) { return Results.NotFound(); }
});

app.MapPut("/api/listeners/{id}/printer-state", (
    string id,
    PrinterStateUpdateRequest request,
    PrinterListenerManager listeners,
    PrinterProfileService profiles,
    LicenseService license) =>
{
    if (!license.CanManageMultipleListeners)
        return Results.Problem("Per-listener printer state requires a Pro or Enterprise License.", statusCode: 403);
    try
    {
        var listener = listeners.Get(id);
        var profile = profiles.Get(listener.Configuration.ProfileId);
        return Results.Ok(listeners.UpdatePrinterState(id, request) with
        {
            DleEotSupported = profile.Capabilities.DleEotStatus,
            AsbSupported = profile.Capabilities.AutomaticStatusBack
        });
    }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
    catch (KeyNotFoundException) { return Results.NotFound(); }
});

app.MapPost("/api/listeners/{id}/printer-state/reset", (
    string id,
    PrinterListenerManager listeners,
    PrinterProfileService profiles,
    LicenseService license) =>
{
    if (!license.CanManageMultipleListeners)
        return Results.Problem("Per-listener printer state requires a Pro or Enterprise License.", statusCode: 403);
    try
    {
        var listener = listeners.Get(id);
        var profile = profiles.Get(listener.Configuration.ProfileId);
        return Results.Ok(listeners.ResetPrinterState(id) with
        {
            DleEotSupported = profile.Capabilities.DleEotStatus,
            AsbSupported = profile.Capabilities.AutomaticStatusBack
        });
    }
    catch (KeyNotFoundException) { return Results.NotFound(); }
});

app.MapGet("/api/stored-graphics", (StoredGraphicService graphics, LicenseService license) =>
    !license.HasPaidAccess
        ? Results.Problem("Stored Logos requires a Lite, Pro, or Enterprise License.", statusCode: 403)
        : Results.Ok(graphics.List()));

app.MapGet("/api/stored-graphics/{keyCode}/content", (string keyCode, StoredGraphicService graphics, LicenseService license) =>
    !license.HasPaidAccess
        ? Results.Problem("Stored Logos requires a Lite, Pro, or Enterprise License.", statusCode: 403)
        : graphics.TryRead(keyCode, out var content, out var contentType)
        ? Results.File(content, contentType)
        : Results.NotFound());

app.MapPost("/api/stored-graphics/{keyCode}", async (string keyCode, HttpRequest request, StoredGraphicService graphics, LicenseService license, CancellationToken cancellationToken) =>
{
    if (!license.HasPaidAccess) return Results.Problem("Stored Logos requires a Lite, Pro, or Enterprise License.", statusCode: 403);
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
    if (!license.HasPaidAccess) return Results.Problem("Stored Logos requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    try { return await graphics.DeleteAsync(keyCode, cancellationToken) ? Results.NoContent() : Results.NotFound(); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapPost("/api/backups/create", async (
    ConfigurationBackupCreateRequest request,
    ConfigurationBackupService backups,
    CancellationToken cancellationToken) =>
{
    try
    {
        var package = await backups.CreateAsync(request, cancellationToken);
        return Results.File(
            package,
            "application/vnd.pos-printer-emulator.backup",
            $"pos-printer-emulator-{DateTimeOffset.Now:yyyyMMdd-HHmmss}{ConfigurationBackupService.FileExtension}");
    }
    catch (Exception exception) { return BackupProblem(exception); }
});

app.MapPost("/api/backups/inspect", async (
    HttpRequest request,
    ConfigurationBackupService backups,
    CancellationToken cancellationToken) =>
{
    try
    {
        var (package, password) = await ReadBackupUploadAsync(request, cancellationToken);
        return Results.Ok(backups.Inspect(package, password));
    }
    catch (Exception exception) { return BackupProblem(exception); }
});

app.MapPost("/api/backups/restore", async (
    HttpRequest request,
    ConfigurationBackupService backups,
    CancellationToken cancellationToken) =>
{
    try
    {
        var (package, password) = await ReadBackupUploadAsync(request, cancellationToken);
        return Results.Ok(await backups.RestoreAsync(package, password, cancellationToken));
    }
    catch (Exception exception) { return BackupProblem(exception); }
});

app.MapGet("/api/support/diagnostics", (LicenseService license, PrinterListenerManager listeners,
    ReceiptStore store, SupportLogProvider logs, StoredGraphicService graphics, PrinterProfileService profiles) =>
{
    var status = license.GetStatus();
    var selectedProfile = profiles.GetSelected();
    var listenerStatus = listeners.GetStatus();
    var reportBuilder = new StringBuilder()
        .AppendLine("POS Printer Emulator Support Diagnostics")
        .AppendLine($"Generated: {DateTimeOffset.Now:O}")
        .AppendLine($"Application version: {ProductInfo.Version}")
        .AppendLine($"Operating system: {Environment.OSVersion}")
        .AppendLine($"Runtime: {Environment.Version}")
        .AppendLine($"64-bit process: {Environment.Is64BitProcess}")
        .AppendLine($"Configured printer listeners: {listenerStatus.Listeners.Count}")
        .AppendLine($"Listening printer listeners: {listenerStatus.ListeningCount}")
        .AppendLine($"License mode: {status.Mode}")
        .AppendLine($"License ID: {status.LicenseId?.ToString() ?? "None"}")
        .AppendLine($"Maintenance status: {status.Maintenance.State}")
        .AppendLine($"Maintenance expiration: {status.Maintenance.ExpiresAt?.ToString("O") ?? "Not applicable"}")
        .AppendLine($"Printer profile: {selectedProfile.Name} ({selectedProfile.Id})")
        .AppendLine($"Profile paper: {selectedProfile.PaperWidthMm} mm / {selectedProfile.PrintableDots} dots")
        .AppendLine($"Receipt jobs currently listed: {store.GetSummaries().Count}")
        .AppendLine($"Stored printer logos: {graphics.List().Count}");

    reportBuilder.AppendLine().AppendLine("Printer listeners").AppendLine("-----------------");
    foreach (var listener in listenerStatus.Listeners)
    {
        var configuration = listener.Configuration;
        var stateSummary = "Unavailable";
        try { stateSummary = listeners.GetPrinterState(configuration.Id).Summary; }
        catch (KeyNotFoundException) { }
        reportBuilder
            .AppendLine($"{configuration.Name} ({configuration.Id})")
            .AppendLine($"  Endpoint: {configuration.BindAddress}:{configuration.Port} / {configuration.Protocol}")
            .AppendLine($"  Profile: {configuration.ProfileId}")
            .AppendLine($"  Enabled/listening/state: {configuration.Enabled}/{listener.Listening}/{listener.State}")
            .AppendLine($"  Last connection: {listener.LastConnection?.ToString("O") ?? "None"}")
            .AppendLine($"  Last error: {listener.LastError ?? "None"}")
            .AppendLine($"  Counters: connections={listener.Counters.AcceptedConnections}, active={listener.Counters.ActiveConnections}, jobs={listener.Counters.ReceivedJobs}, completed={listener.Counters.CompletedJobs}, rejected={listener.Counters.RejectedJobs}, failed={listener.Counters.FailedJobs}, bytes={listener.Counters.ReceivedBytes}")
            .AppendLine($"  Simulated state: {stateSummary}");
    }

    var report = reportBuilder
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

app.MapGet("/api/support/activation-diagnostics", (LicenseService license) =>
{
    var status = license.GetStatus();
    var storage = license.GetStorageDiagnostics();
    var report = new StringBuilder()
        .AppendLine("POS Printer Emulator Activation Diagnostics")
        .AppendLine($"Generated: {DateTimeOffset.Now:O}")
        .AppendLine($"Application version: {ProductInfo.Version}")
        .AppendLine($"Operating system: {Environment.OSVersion}")
        .AppendLine($"Runtime: {Environment.Version}")
        .AppendLine($"64-bit process: {Environment.Is64BitProcess}")
        .AppendLine($"License mode: {status.Mode}")
        .AppendLine($"Maintenance status: {status.Maintenance.State}")
        .AppendLine($"Maintenance expiration: {status.Maintenance.ExpiresAt?.ToString("O") ?? "Not applicable"}")
        .AppendLine($"Data path: {storage.DataPath}")
        .AppendLine($"Data directory exists: {storage.DataDirectoryExists}")
        .AppendLine($"Registration file exists: {storage.RegistrationFileExists}")
        .AppendLine($"License file exists: {storage.LicenseFileExists}")
        .AppendLine($"Maintenance file exists: {storage.MaintenanceFileExists}")
        .AppendLine($"Promotion file exists: {storage.PromotionFileExists}")
        .AppendLine($"Promotion status: {status.Promotion.State}")
        .AppendLine($"Promotion expiration: {status.Promotion.ExpiresAt?.ToString("O") ?? "Not applicable"}")
        .AppendLine($"Last storage error type: {storage.LastErrorType ?? "None"}")
        .AppendLine($"Last storage error: {storage.LastErrorMessage ?? "None"}")
        .AppendLine()
        .AppendLine("This report does not contain the activation key, customer registration data, or receipt contents.")
        .ToString();
    return Results.File(Encoding.UTF8.GetBytes(report), "text/plain", $"POS-Printer-Emulator-Activation-Diagnostics-{DateTime.Now:yyyyMMdd-HHmmss}.txt");
});

app.MapPost("/api/support/connection-diagnostics", async (
    ConnectionDiagnosticsService diagnostics,
    CancellationToken cancellationToken) =>
{
    var report = await diagnostics.RunAsync(cancellationToken);
    return Results.Ok(new { Report = report, PackagePreview = diagnostics.PreviewPackage(report) });
});

app.MapGet("/api/support/connection-diagnostics/package/{packageId}", async (
    string packageId,
    ConnectionDiagnosticsService diagnostics,
    CancellationToken cancellationToken) =>
{
    if (!diagnostics.TryGetReport(packageId, out var report)) return Results.NotFound();
    var package = await diagnostics.CreatePackageAsync(report, cancellationToken);
    return Results.File(package, "application/zip", $"POS-Printer-Emulator-Support-{report.PackageId}.zip");
});

app.MapPost("/api/support/connection-diagnostics/listeners/{id}/restart", async (
    string id,
    PrinterListenerManager listeners,
    PrinterProfileService profiles,
    CancellationToken cancellationToken) =>
{
    try { return Results.Ok((await listeners.RestartForDiagnosticsAsync(id, cancellationToken)).ToResponse(profiles)); }
    catch (Exception exception) { return ListenerProblem(exception); }
});

app.MapPost("/api/support/requests/preview", (
    SupportRequestInput request,
    SupportRequestService support,
    PrinterListenerManager listeners) =>
{
    try
    {
        var attachments = SupportRequestService.DecodeAttachments(request.Attachments);
        var summary = string.Join("; ", listeners.GetStatus().Listeners.Select(listener =>
            $"{listener.Configuration.Name}: {listener.Configuration.BindAddress}:{listener.Configuration.Port}, {listener.State}"));
        return Results.Ok(support.Preview(request, summary, attachments));
    }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapPost("/api/support/requests", async (
    SupportRequestInput request,
    SupportRequestService support,
    PrinterListenerManager listeners,
    LicenseService license,
    CancellationToken cancellationToken) =>
{
    try
    {
        var attachments = SupportRequestService.DecodeAttachments(request.Attachments);
        var summary = string.Join("; ", listeners.GetStatus().Listeners.Select(listener =>
            $"{listener.Configuration.Name}: {listener.Configuration.BindAddress}:{listener.Configuration.Port}, {listener.State}"));
        var result = await support.SubmitAsync(request, license.GetStatus(), summary, attachments, cancellationToken);
        return result.State == "SavedForRetry" ? Results.Json(result, statusCode: 202) : Results.Ok(result);
    }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
    catch (InvalidOperationException exception) { return Results.Problem(exception.Message, statusCode: 403); }
});

app.MapGet("/api/support/requests/drafts", (SupportRequestService support) => Results.Ok(support.ListDrafts()));

app.MapPost("/api/support/requests/drafts/{reference}/retry", async (
    string reference, SupportRequestService support, CancellationToken cancellationToken) =>
{
    try { return Results.Ok(await support.RetryAsync(reference, cancellationToken)); }
    catch (KeyNotFoundException exception) { return Results.Problem(exception.Message, statusCode: 404); }
    catch (HttpRequestException) { return Results.Problem("The support service is still unavailable. The draft was preserved.", statusCode: 503); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapDelete("/api/support/requests/drafts/{reference}", (string reference, SupportRequestService support) =>
{
    try { return support.DeleteDraft(reference) ? Results.NoContent() : Results.NotFound(); }
    catch (ArgumentException exception) { return Results.Problem(exception.Message, statusCode: 400); }
});

app.MapPost("/api/license/activate", async (
    ActivationRequest request,
    LicenseService license,
    ReceiptStore store,
    PrinterListenerManager listeners,
    IUsageTelemetry telemetry,
    ILoggerFactory loggerFactory,
    CancellationToken cancellationToken) =>
{
    var logger = loggerFactory.CreateLogger("LicenseActivation");
    LicenseStatus status;

    try
    {
        status = license.Activate(request.CustomerName, request.EmailAddress, request.ActivationKey);
    }
    catch (InvalidOperationException exception)
    {
        return Results.Problem(exception.Message, statusCode: 400);
    }
    catch (Exception exception)
    {
        logger.LogError(exception, "A validated license could not be saved to local storage");
        return Results.Problem(
            "The activation key could not be saved on this computer. Download Activation Diagnostics from this License page and send it to support, then try again.",
            statusCode: 500);
    }

    try
    {
        store.EnablePaidHistory();
    }
    catch (Exception exception)
    {
        logger.LogError(
            exception,
            "License {LicenseId} was activated, but paid receipt history could not be initialized",
            status.LicenseId);
    }

    try
    {
        await listeners.ReconcileAsync(cancellationToken);
    }
    catch (Exception exception)
    {
        logger.LogError(
            exception,
            "License {LicenseId} was activated, but printer listeners could not be reconciled",
            status.LicenseId);
    }

    telemetry.RecordActivation();
    return Results.Ok(license.GetStatus());
});

app.MapPost("/api/license/maintenance/apply", (
    MaintenanceEntitlementRequest request,
    LicenseService license,
    ILoggerFactory loggerFactory) =>
{
    try
    {
        return Results.Ok(license.InstallMaintenanceEntitlement(request.EntitlementToken));
    }
    catch (InvalidOperationException exception)
    {
        return Results.Problem(exception.Message, statusCode: 400);
    }
    catch (Exception exception)
    {
        loggerFactory.CreateLogger("MaintenanceEntitlement")
            .LogError(exception, "A validated maintenance entitlement could not be saved to local storage");
        return Results.Problem(
            "The maintenance renewal could not be saved on this computer. Download Activation Diagnostics and try again.",
            statusCode: 500);
    }
});

app.MapPost("/api/license/promotion/apply", (
    PromotionEntitlementRequest request,
    LicenseService license,
    ILoggerFactory loggerFactory) =>
{
    try
    {
        return Results.Ok(license.InstallPromotionEntitlement(request.EntitlementToken));
    }
    catch (InvalidOperationException exception)
    {
        return Results.Problem(exception.Message, statusCode: 400);
    }
    catch (Exception exception)
    {
        loggerFactory.CreateLogger("PromotionEntitlement")
            .LogError(exception, "A validated promotion entitlement could not be saved to local storage");
        return Results.Problem(
            "The promotional access key could not be saved on this computer. Download Activation Diagnostics and try again.",
            statusCode: 500);
    }
});

app.MapGet("/api/license/promotion/offer", async (
    PromotionAccessService promotion,
    CancellationToken cancellationToken) =>
{
    try
    {
        return Results.Ok(await promotion.GetOfferAsync(cancellationToken));
    }
    catch (InvalidOperationException exception)
    {
        return Results.Problem(exception.Message, statusCode: 409);
    }
});

app.MapPost("/api/license/promotion/start", async (
    StartPromotionRequest request,
    PromotionAccessService promotion,
    CancellationToken cancellationToken) =>
{
    try
    {
        return Results.Ok(await promotion.StartAsync(request.GrantedTier, cancellationToken));
    }
    catch (InvalidOperationException exception)
    {
        return Results.Problem(exception.Message, statusCode: 409);
    }
});

app.MapPost("/api/license/maintenance/refresh", async (
    MaintenanceRefreshService maintenance,
    CancellationToken cancellationToken) =>
{
    try
    {
        return Results.Ok(await maintenance.RefreshAsync(cancellationToken));
    }
    catch (InvalidOperationException exception)
    {
        return Results.Problem(exception.Message, statusCode: 400);
    }
});

app.MapGet("/api/jobs", (string? listenerId, ReceiptStore store) => store.GetSummaries(listenerId));

app.MapGet("/api/jobs/{id:guid}", (Guid id, ReceiptStore store) =>
{
    var job = store.Get(id);
    return job is null
        ? Results.NotFound()
        : Results.Ok(JobResponse(job));
});

app.MapPost("/api/jobs/{id:guid}/receipt-image/authorize", (
    Guid id,
    ReceiptImageAuthorizationRequest request,
    ReceiptStore store,
    LicenseService license,
    ILoggerFactory loggerFactory) =>
{
    if (!license.GetStatus().Features.ReceiptImages)
        return Results.Problem("Receipt image sharing requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    if (store.Get(id) is null)
        return Results.NotFound();

    var action = request.Action?.Trim().ToLowerInvariant();
    if (action is not ("copy" or "save"))
        return Results.Problem("Choose copy or save for the receipt image action.", statusCode: 400);

    loggerFactory.CreateLogger("ReceiptImageExport")
        .LogInformation("Receipt image {ReceiptImageAction} authorized for job {JobId}", action, id);
    return Results.Ok(new { Authorized = true });
});

app.MapDelete("/api/jobs/{id:guid}", (Guid id, ReceiptStore store) =>
{
    try { return store.Delete(id) ? Results.NoContent() : Results.NotFound(); }
    catch (InvalidOperationException exception) { return Results.Problem(exception.Message, statusCode: 500); }
});

app.MapDelete("/api/jobs", (string? listenerId, ReceiptStore store) =>
{
    try { return Results.Ok(new { Removed = store.Clear(listenerId) }); }
    catch (InvalidOperationException exception) { return Results.Problem(exception.Message, statusCode: 500); }
});

app.MapPost("/api/sample", (ReceiptProcessor processor, PrinterListenerManager listeners, PrinterProfileService profiles) =>
{
    var configuration = listeners.Get(PrinterListenerDefaults.DefaultId).Configuration;
    var profile = profiles.Get(configuration.ProfileId);
    var context = new PrinterListenerJobContext(configuration.Id, configuration.Name, configuration.Port);
    var job = processor.ProcessTestReceipt(SampleReceipt.Create(), profile, context, out var rejection);
    return job is null ? Results.Problem(rejection, statusCode: 400) : Results.Ok(JobResponse(job));
});

app.MapPost("/api/captures/import", async (
    HttpRequest request,
    LicenseService license,
    CapturePackageService captures,
    ReceiptProcessor processor,
    PrinterOptions options,
    CancellationToken cancellationToken) =>
{
    if (!license.HasPaidAccess)
        return Results.Problem("Capture import requires a Lite, Pro, or Enterprise License.", statusCode: 403);
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
            imported.ListenerId,
            imported.ListenerName,
            imported.ListenerPort,
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
    if (!license.HasPaidAccess)
        return Results.Problem("Receipt replay requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    var source = store.Get(id);
    if (source is null) return Results.NotFound();
    var replayed = processor.Replay(source, out var rejection);
    return replayed is null
        ? Results.Problem(rejection, statusCode: 400)
        : Results.Ok(new { replayed.Id, replayed.Origin });
});

app.MapGet("/api/jobs/{id:guid}/capture", (Guid id, ReceiptStore store, CapturePackageService captures, LicenseService license) =>
{
    if (!license.HasPaidAccess)
        return Results.Problem("Capture-package export requires a Lite, Pro, or Enterprise License.", statusCode: 403);
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
    if (!license.HasPaidAccess)
        return Results.Problem("Raw-data export requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    var job = store.Get(id);
    return job is null ? Results.NotFound() : Results.File(job.RawPayload, "application/octet-stream", $"receipt-{id:N}.bin");
});

app.MapGet("/api/jobs/{id:guid}/text", (Guid id, ReceiptStore store, LicenseService license) =>
{
    if (!license.HasPaidAccess)
        return Results.Problem("Text export requires a Lite, Pro, or Enterprise License.", statusCode: 403);
    var job = store.Get(id);
    return job is null
        ? Results.NotFound()
        : Results.File(Encoding.UTF8.GetBytes(job.Receipt.PlainText), "text/plain", $"receipt-{id:N}.txt");
});

static IResult ListenerProblem(Exception exception) => exception switch
{
    UnauthorizedAccessException => Results.Problem(exception.Message, statusCode: 403),
    KeyNotFoundException => Results.Problem(exception.Message, statusCode: 404),
    ArgumentException => Results.Problem(exception.Message, statusCode: 400),
    InvalidOperationException => Results.Problem(exception.Message, statusCode: 409),
    _ => Results.Problem("The printer listener operation could not be completed. Review Support diagnostics and try again.", statusCode: 500)
};

static IResult BackupProblem(Exception exception) => exception switch
{
    ArgumentException => Results.Problem(exception.Message, statusCode: 400),
    InvalidDataException => Results.Problem(exception.Message, statusCode: 400),
    UnauthorizedAccessException => Results.Problem(exception.Message, statusCode: 403),
    InvalidOperationException => Results.Problem(exception.Message, statusCode: 409),
    OperationCanceledException => Results.Problem("The backup operation was cancelled.", statusCode: 408),
    _ => Results.Problem("The backup operation could not be completed. No licensing credentials were changed.", statusCode: 500)
};

static async Task<(byte[] Package, string Password)> ReadBackupUploadAsync(
    HttpRequest request,
    CancellationToken cancellationToken)
{
    if (!request.HasFormContentType)
        throw new ArgumentException("Select a POS Printer Emulator backup file and enter its password.");
    var form = await request.ReadFormAsync(cancellationToken);
    var file = form.Files.GetFile("file") ?? throw new ArgumentException("Select a .ppebackup file.");
    if (!ConfigurationBackupService.IsSupportedFileName(file.FileName))
        throw new ArgumentException("Select a .ppebackup file created by POS Printer Emulator. Files ending in .ppebackup.zip from version 0.3.34 are also accepted.");
    if (file.Length <= 0 || file.Length > BackupPackageCodec.MaximumPackageBytes)
        throw new ArgumentException("Backup files must be between 1 byte and 128 MB.");
    var password = form["password"].ToString();
    await using var output = new MemoryStream((int)file.Length);
    await file.CopyToAsync(output, cancellationToken);
    return (output.ToArray(), password);
}

static object JobResponse(ReceiptJob job) => new
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
    job.ListenerId,
    job.ListenerName,
    job.ListenerPort,
    job.Receipt.Lines,
    job.Receipt.Commands,
    job.Receipt.PlainText,
    Hex = Convert.ToHexString(job.RawPayload)
        .Chunk(2)
        .Select(chars => new string(chars))
        .Chunk(16)
        .Select(row => string.Join(" ", row))
};

app.MapFallbackToFile("index.html");
app.Run();

public partial class Program;
