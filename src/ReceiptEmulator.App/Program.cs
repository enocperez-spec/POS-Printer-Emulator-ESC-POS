using System.Reflection;
using System.Text;
using ReceiptEmulator;

var setupExitCode = await WindowsSetupCommand.TryRunAsync(args);
if (setupExitCode is not null)
{
    Environment.ExitCode = setupExitCode.Value;
    return;
}

var builder = WebApplication.CreateBuilder(args);
builder.Host.UseWindowsService(options => options.ServiceName = "POS Printer Emulator");
builder.WebHost.UseUrls(builder.Configuration["Viewer:Url"] ?? "http://127.0.0.1:5187");

var printerOptions = builder.Configuration.GetSection("Printer").Get<PrinterOptions>() ?? new PrinterOptions();
builder.Services.AddSingleton(printerOptions);
builder.Services.AddSingleton<EscPosParser>();
builder.Services.AddSingleton<ReceiptStore>();
builder.Services.AddSingleton<LicenseService>();
builder.Services.AddSingleton<ReceiptProcessor>();
builder.Services.AddSingleton<ServiceRuntimeState>();
builder.Services.AddHostedService<TcpReceiptListener>();

var app = builder.Build();
app.UseDefaultFiles();
app.UseStaticFiles();

app.MapGet("/api/status", (ServiceRuntimeState runtime, LicenseService license, PrinterOptions options) =>
    new ServiceStatus(
        runtime.Listening,
        $"{options.BindAddress}:{options.Port}",
        runtime.LastConnection,
        Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "0.3.0",
        license.GetStatus()));

app.MapPost("/api/license/activate", (ActivationRequest request, LicenseService license, ReceiptStore store) =>
{
    try
    {
        var status = license.Activate(request.CustomerName, request.EmailAddress, request.ActivationKey);
        store.EnableFullHistory();
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
