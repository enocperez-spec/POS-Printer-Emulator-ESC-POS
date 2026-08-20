using System.Diagnostics;
using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

return await CertificationProgram.RunAsync(args);

internal static class CertificationProgram
{
    private static readonly string Root = FindRepositoryRoot();
    private static readonly JsonSerializerOptions JsonOptions = new() { WriteIndented = true };

    public static async Task<int> RunAsync(string[] args)
    {
        var command = args.FirstOrDefault()?.ToLowerInvariant() ?? "help";
        if (command is "help" or "--help" or "-h")
        {
            PrintHelp();
            return 0;
        }

        var options = ParseOptions(args.Skip(1).ToArray());
        if (command is "desktop-profile-install" or "desktop-profile-verify" or "desktop-profile-remove")
        {
            try
            {
                return command switch
                {
                    "desktop-profile-install" => InstallDesktopProfile(options),
                    "desktop-profile-verify" => VerifyDesktopProfile(options),
                    "desktop-profile-remove" => RemoveDesktopProfile(options),
                    _ => 1
                };
            }
            catch (Exception exception)
            {
                Console.Error.WriteLine($"Desktop certification profile command failed: {exception.Message}");
                return 1;
            }
        }
        if (command is "rollout-start" or "rollout-set" or "rollout-approve")
        {
            try
            {
                return command switch
                {
                    "rollout-start" => await StartRolloutAsync(options),
                    "rollout-set" => UpdateRolloutGate(options),
                    "rollout-approve" => ApproveRollout(options),
                    _ => 1
                };
            }
            catch (Exception exception)
            {
                Console.Error.WriteLine($"Rollout command failed: {exception.Message}");
                return 1;
            }
        }

        var startedAt = DateTimeOffset.UtcNow;
        var runId = $"cert-{startedAt:yyyyMMdd-HHmmss}-{RandomNumberGenerator.GetHexString(4).ToLowerInvariant()}";
        var reportDirectory = Path.GetFullPath(options.GetValueOrDefault(
            "report-directory",
            Path.Combine(Root, "artifacts", "certification", runId)));
        var report = new CertificationReport
        {
            RunId = runId,
            CorrelationId = Guid.NewGuid().ToString("D"),
            Environment = command switch
            {
                "preflight" => "staging",
                "rollout-verify" => "production-readiness",
                "e2e-verify" => "customer-experience",
                _ => "local-source-gate"
            },
            StartedAtUtc = startedAt,
            RepositoryCommit = await GitValueAsync("rev-parse", "HEAD"),
            RepositoryBranch = await GitValueAsync("branch", "--show-current"),
            ProductVersion = ReadProductVersion()
        };

        try
        {
            switch (command)
            {
                case "local-gate":
                    await RunLocalGateAsync(report, options);
                    break;
                case "preflight":
                    await RunPreflightAsync(report, RequireOption(options, "config"));
                    break;
                case "scan-customer-surfaces":
                    report.Checks.Add(ScanCustomerSurfaces());
                    break;
                case "validate-matrix":
                    report.Checks.Add(ValidateScenarioMatrix());
                    break;
                case "validate-rollout-policy":
                    report.Checks.Add(ValidateRolloutPolicy());
                    break;
                case "rollout-verify":
                    report.Checks.Add(await ValidateRolloutRecordAsync(
                        RequireOption(options, "record"),
                        options.GetValueOrDefault("installer")));
                    break;
                case "e2e-verify":
                    var experiencePath = Path.GetFullPath(RequireOption(options, "record"));
                    var experience = ReadExperienceRecord(experiencePath);
                    report.ArtifactSha256 = experience.InstallerSha256;
                    report.Checks.Add(await ValidateExperienceRecordAsync(
                        experiencePath,
                        experience,
                        RequireOption(options, "installer")));
                    break;
                default:
                    throw new ArgumentException($"Unknown command '{command}'.");
            }
        }
        catch (Exception exception)
        {
            report.Checks.Add(new CertificationCheck
            {
                Name = "Certification runner",
                Category = "Runner",
                Critical = true,
                Status = "Failed",
                Summary = exception.Message
            });
        }
        finally
        {
            report.CompletedAtUtc = DateTimeOffset.UtcNow;
            report.Status = report.Checks.All(check => check.Status == "Passed") ? "Passed" : "Failed";
            WriteReport(reportDirectory, report);
        }

        Console.WriteLine($"Certification {report.Status.ToLowerInvariant()}: {report.RunId}");
        Console.WriteLine($"Correlation ID: {report.CorrelationId}");
        Console.WriteLine($"Evidence: {reportDirectory}");
        foreach (var check in report.Checks)
        {
            Console.WriteLine($"[{check.Status}] {check.Name}: {check.Summary}");
        }

        return report.Status == "Passed" ? 0 : 1;
    }

    private static readonly (string Id, string Title, bool Critical)[] RolloutGateDefinitions =
    [
        ("scope-review", "Scope and change review", true),
        ("sandbox-preflight", "Sandbox isolation and preflight", true),
        ("source-gate", "Source, build, and installer integrity gate", true),
        ("sandbox-deployment", "Sandbox deployment and database initialization", true),
        ("web-endpoints", "Website and endpoint smoke tests", true),
        ("account-security", "Customer account and security journeys", true),
        ("installer-desktop", "Installer and desktop application", true),
        ("entitlements", "Trial and paid-license entitlements", true),
        ("device-linking", "Computer linking, transfer, and recovery", true),
        ("paypal-commerce", "Purchases, upgrades, and PayPal sandbox", true),
        ("maintenance-support", "Maintenance and Support journeys", true),
        ("email-communications", "Email and communication templates", true),
        ("admin-audit", "Admin Portal operations and audit", true),
        ("ux-accessibility-seo", "Compatibility, usability, accessibility, and SEO", true),
        ("failure-rollback", "Failure recovery and rollback rehearsal", true),
        ("manual-customer", "Independent first-time customer test", true),
        ("evidence-review", "Evidence completeness and defect review", true),
        ("production-plan", "Production deployment and monitoring plan", true)
    ];

    private static string DesktopProfilePath(IReadOnlyDictionary<string, string> options) =>
        Path.GetFullPath(options.GetValueOrDefault(
            "destination",
            Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                "POSPrinterEmulator",
                "external-services.json")));

    private static int InstallDesktopProfile(IReadOnlyDictionary<string, string> options)
    {
        var source = Path.GetFullPath(options.GetValueOrDefault(
            "source",
            Path.Combine(Root, "certification", "desktop-external-services.example.json")));
        ValidateDesktopProfile(source);
        var destination = DesktopProfilePath(options);
        Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
        var temporary = destination + ".tmp";
        File.Copy(source, temporary, overwrite: true);
        File.Move(temporary, destination, overwrite: true);
        ValidateDesktopProfile(destination);
        Console.WriteLine($"Installed the fail-closed desktop certification profile at {destination}.");
        Console.WriteLine("Restart the POS Printer Emulator Windows service before continuing.");
        return 0;
    }

    private static int VerifyDesktopProfile(IReadOnlyDictionary<string, string> options)
    {
        var path = DesktopProfilePath(options);
        ValidateDesktopProfile(path);
        Console.WriteLine($"Desktop certification profile verified: {path}");
        return 0;
    }

    private static int RemoveDesktopProfile(IReadOnlyDictionary<string, string> options)
    {
        if (!options.TryGetValue("confirm", out var confirmation) ||
            !confirmation.Equals("REMOVE-CERTIFICATION-PROFILE", StringComparison.Ordinal))
        {
            throw new InvalidOperationException(
                "--confirm REMOVE-CERTIFICATION-PROFILE is required.");
        }
        var path = DesktopProfilePath(options);
        if (File.Exists(path)) File.Delete(path);
        Console.WriteLine($"Desktop certification profile removed: {path}");
        Console.WriteLine("Restart the POS Printer Emulator Windows service to restore the packaged production profile.");
        return 0;
    }

    private static void ValidateDesktopProfile(string path)
    {
        if (!File.Exists(path))
        {
            throw new FileNotFoundException("The desktop external-services profile was not found.", path);
        }
        using var document = JsonDocument.Parse(File.ReadAllText(path));
        if (!document.RootElement.TryGetProperty("ExternalServices", out var services) ||
            services.ValueKind != JsonValueKind.Object)
        {
            throw new InvalidDataException("ExternalServices must be a JSON object.");
        }
        var profile = services.GetProperty("Profile").GetString();
        if (profile is null ||
            !(profile.Equals("Certification", StringComparison.OrdinalIgnoreCase) ||
              profile.Equals("Sandbox", StringComparison.OrdinalIgnoreCase) ||
              profile.Equals("Staging", StringComparison.OrdinalIgnoreCase)))
        {
            throw new InvalidDataException("The desktop profile must explicitly select Certification, Sandbox, or Staging.");
        }
        var allowedHosts = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
        {
            "sandbox.posprinteremulator.com",
            "admin-sandbox.posprinteremulator.com",
            "buy-sandbox.posprinteremulator.com",
            "userportal-sandbox.posprinteremulator.com",
            "support-sandbox.posprinteremulator.com",
        };
        foreach (var key in new[]
                 {
                     "TelemetryEndpoint", "AccountLinkEndpoint", "DeviceEntitlementEndpoint",
                     "DeviceUnlinkEndpoint", "PromotionBaseUrl", "SupportBaseUrl", "BuyBaseUrl",
                     "CustomerPortalBaseUrl", "WebsiteBaseUrl", "SupportWebsiteBaseUrl"
                 })
        {
            if (!services.TryGetProperty(key, out var value) ||
                !Uri.TryCreate(value.GetString(), UriKind.Absolute, out var uri) ||
                uri.Scheme != Uri.UriSchemeHttps)
            {
                throw new InvalidDataException($"{key} must be an absolute HTTPS URL.");
            }
            if (!allowedHosts.Contains(uri.Host))
            {
                throw new InvalidDataException($"{key} points outside the certification host allowlist.");
            }
        }
    }

    private static readonly string[] ExperienceJourneyIds =
    [
        "discover-license",
        "account-verification",
        "account-security",
        "install-trial",
        "trial-usage",
        "paid-purchases",
        "computer-link",
        "tier-validation",
        "upgrade-transfer-reinstall",
        "maintenance-updates",
        "support-communications",
        "failure-recovery",
        "accessibility-responsive",
        "admin-reconciliation",
        "first-time-comprehension"
    ];

    private static async Task<int> StartRolloutAsync(IReadOnlyDictionary<string, string> options)
    {
        var version = NormalizeVersion(RequireOption(options, "version"));
        var productVersion = NormalizeVersion(ReadProductVersion());
        if (!version.Equals(productVersion, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException(
                $"Rollout version {version} does not match repository version {productVersion}.");
        }

        var installerPath = Path.GetFullPath(RequireOption(options, "installer"));
        var installerHash = ComputeAndValidateInstallerHash(installerPath);
        var commit = await GitValueAsync("rev-parse", "HEAD");
        if (!Regex.IsMatch(commit, "^[0-9a-f]{40}$", RegexOptions.IgnoreCase))
        {
            throw new InvalidOperationException("The current Git commit could not be determined.");
        }

        var recordPath = Path.GetFullPath(options.GetValueOrDefault(
            "record",
            Path.Combine(Root, "artifacts", "rollouts", $"v{version}.json")));
        if (File.Exists(recordPath))
        {
            throw new IOException($"A rollout record already exists at {recordPath}.");
        }

        var now = DateTimeOffset.UtcNow;
        var actor = options.GetValueOrDefault("actor", Environment.UserName).Trim();
        var record = new RolloutRecord
        {
            SchemaVersion = 1,
            RolloutId = $"rollout-{version}-{now:yyyyMMddHHmmss}",
            ProductVersion = version,
            RepositoryCommit = commit,
            InstallerPath = Path.GetRelativePath(Root, installerPath).Replace('\\', '/'),
            InstallerSha256 = installerHash,
            Status = "Draft",
            CreatedAtUtc = now,
            GateUpdatedAtUtc = now,
            CreatedBy = actor,
            Gates = RolloutGateDefinitions.Select(definition => new RolloutGate
            {
                Id = definition.Id,
                Title = definition.Title,
                Critical = definition.Critical,
                Status = "Pending"
            }).ToList(),
            Events =
            [
                new RolloutEvent
                {
                    OccurredAtUtc = now,
                    Actor = actor,
                    Action = "RolloutCreated",
                    Summary = $"Created rollout for v{version} at commit {commit}."
                }
            ]
        };
        WriteRolloutRecord(recordPath, record);
        Console.WriteLine($"Created rollout record: {recordPath}");
        Console.WriteLine($"Rollout ID: {record.RolloutId}");
        Console.WriteLine("All critical gates are pending; production readiness is blocked.");
        return 0;
    }

    private static int UpdateRolloutGate(IReadOnlyDictionary<string, string> options)
    {
        var recordPath = Path.GetFullPath(RequireOption(options, "record"));
        var record = ReadRolloutRecord(recordPath);
        var gateId = RequireOption(options, "gate").Trim().ToLowerInvariant();
        var status = RequireOption(options, "status").Trim();
        var allowedStatuses = new[] { "Pending", "Passed", "Failed", "NotApplicable" };
        var canonicalStatus = allowedStatuses.FirstOrDefault(
            candidate => candidate.Equals(status, StringComparison.OrdinalIgnoreCase))
            ?? throw new ArgumentException(
                "--status must be Pending, Passed, Failed, or NotApplicable.");
        var gate = record.Gates.SingleOrDefault(item =>
                       item.Id.Equals(gateId, StringComparison.OrdinalIgnoreCase))
                   ?? throw new ArgumentException($"Unknown rollout gate '{gateId}'.");
        if (gate.Critical && canonicalStatus == "NotApplicable")
        {
            throw new InvalidOperationException($"Critical gate '{gate.Id}' cannot be NotApplicable.");
        }

        var evidence = options.GetValueOrDefault("evidence", "").Trim();
        if (canonicalStatus == "Passed" && evidence.Length == 0 && gate.Evidence.Count == 0)
        {
            throw new InvalidOperationException(
                $"Passing gate '{gate.Id}' requires --evidence with a local path or HTTPS URL.");
        }
        if (evidence.Length > 0)
        {
            ValidateEvidenceReference(evidence, recordPath);
            if (!gate.Evidence.Contains(evidence, StringComparer.OrdinalIgnoreCase))
            {
                gate.Evidence.Add(evidence);
            }
        }

        var now = DateTimeOffset.UtcNow;
        var actor = options.GetValueOrDefault("actor", Environment.UserName).Trim();
        var previous = gate.Status;
        gate.Status = canonicalStatus;
        gate.Notes = options.GetValueOrDefault("notes", gate.Notes).Trim();
        gate.UpdatedAtUtc = now;
        gate.UpdatedBy = actor;
        record.GateUpdatedAtUtc = now;
        record.Status = canonicalStatus == "Failed" ? "Blocked" : "InReview";
        InvalidateApprovals(record);
        record.Events.Add(new RolloutEvent
        {
            OccurredAtUtc = now,
            Actor = actor,
            Action = "GateUpdated",
            GateId = gate.Id,
            Summary = $"{gate.Title}: {previous} → {canonicalStatus}."
        });
        WriteRolloutRecord(recordPath, record);
        Console.WriteLine($"Updated {gate.Id}: {canonicalStatus}");
        Console.WriteLine("Existing approvals were invalidated because rollout evidence changed.");
        return 0;
    }

    private static int ApproveRollout(IReadOnlyDictionary<string, string> options)
    {
        var recordPath = Path.GetFullPath(RequireOption(options, "record"));
        var record = ReadRolloutRecord(recordPath);
        var role = RequireOption(options, "role").Trim().ToLowerInvariant();
        var approver = RequireOption(options, "name").Trim();
        if (approver.Length < 2)
        {
            throw new ArgumentException("--name must identify the approving person.");
        }
        if (record.Gates.Any(gate => gate.Critical && gate.Status != "Passed"))
        {
            throw new InvalidOperationException(
                "All critical gates must pass before approvals can be recorded.");
        }
        foreach (var gate in record.Gates.Where(gate => gate.Status == "Passed"))
        {
            if (gate.Evidence.Count == 0)
            {
                throw new InvalidOperationException(
                    $"Passed gate '{gate.Id}' has no evidence.");
            }
        }

        var now = DateTimeOffset.UtcNow;
        var approval = new RolloutApproval
        {
            Role = role,
            Name = approver,
            ApprovedAtUtc = now,
            Commit = record.RepositoryCommit,
            InstallerSha256 = record.InstallerSha256
        };
        switch (role)
        {
            case "release-owner":
                record.Approvals.ReleaseOwner = approval;
                break;
            case "test-owner":
                record.Approvals.TestOwner = approval;
                break;
            case "rollback-owner":
                record.Approvals.RollbackOwner = approval;
                break;
            default:
                throw new ArgumentException(
                    "--role must be release-owner, test-owner, or rollback-owner.");
        }

        record.Events.Add(new RolloutEvent
        {
            OccurredAtUtc = now,
            Actor = approver,
            Action = "ApprovalRecorded",
            Summary = $"{role} approval recorded for commit {record.RepositoryCommit}."
        });
        record.Status = AllApprovalsPresent(record) ? "ReadyForProduction" : "AwaitingApprovals";
        WriteRolloutRecord(recordPath, record);
        Console.WriteLine($"Recorded {role} approval from {approver}.");
        Console.WriteLine($"Rollout status: {record.Status}");
        return 0;
    }

    private static async Task<CertificationCheck> ValidateRolloutRecordAsync(
        string recordOption,
        string? installerOption)
    {
        const string name = "Sandbox rollout production readiness";
        try
        {
            var recordPath = Path.GetFullPath(recordOption);
            var record = ReadRolloutRecord(recordPath);
            if (record.SchemaVersion != 1)
            {
                throw new InvalidDataException("Unsupported rollout record schema.");
            }
            var expectedGateIds = RolloutGateDefinitions.Select(item => item.Id).ToHashSet(
                StringComparer.OrdinalIgnoreCase);
            var actualGateIds = record.Gates.Select(item => item.Id).ToArray();
            if (actualGateIds.Distinct(StringComparer.OrdinalIgnoreCase).Count() != actualGateIds.Length ||
                !expectedGateIds.SetEquals(actualGateIds))
            {
                throw new InvalidDataException(
                    "The rollout record does not contain the exact required gate set.");
            }
            var incomplete = record.Gates.Where(gate => gate.Status != "Passed").Select(gate => gate.Id).ToArray();
            if (incomplete.Length > 0)
            {
                throw new InvalidDataException(
                    "Production is blocked by incomplete gates: " + string.Join(", ", incomplete));
            }
            foreach (var gate in record.Gates)
            {
                if (gate.Evidence.Count == 0)
                {
                    throw new InvalidDataException($"Gate '{gate.Id}' has no evidence.");
                }
                foreach (var evidence in gate.Evidence)
                {
                    ValidateEvidenceReference(evidence, recordPath);
                }
            }
            var customerExperienceGate = record.Gates.Single(gate =>
                gate.Id.Equals("manual-customer", StringComparison.OrdinalIgnoreCase));
            ValidateCustomerExperienceEvidence(
                customerExperienceGate,
                recordPath,
                record);
            if (!AllApprovalsPresent(record))
            {
                throw new InvalidDataException(
                    "Release-owner, test-owner, and rollback-owner approvals are required.");
            }
            foreach (var approval in new[]
                     {
                         record.Approvals.ReleaseOwner!,
                         record.Approvals.TestOwner!,
                         record.Approvals.RollbackOwner!
                     })
            {
                if (approval.ApprovedAtUtc < record.GateUpdatedAtUtc ||
                    !approval.Commit.Equals(record.RepositoryCommit, StringComparison.OrdinalIgnoreCase) ||
                    !approval.InstallerSha256.Equals(record.InstallerSha256, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidDataException(
                        $"The {approval.Role} approval is stale or bound to a different artifact.");
                }
            }
            if (DateTimeOffset.UtcNow - record.GateUpdatedAtUtc > TimeSpan.FromDays(14))
            {
                throw new InvalidDataException(
                    "Sandbox gate evidence is older than 14 days and must be renewed.");
            }

            var currentCommit = await GitValueAsync("rev-parse", "HEAD");
            if (!currentCommit.Equals(record.RepositoryCommit, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException(
                    $"Current commit {currentCommit} differs from approved commit {record.RepositoryCommit}.");
            }
            var dirty = await GitValueAsync("status", "--porcelain");
            if (dirty.Length > 0)
            {
                throw new InvalidDataException(
                    "The working tree is not clean; production must use the exact approved commit.");
            }
            var currentVersion = NormalizeVersion(ReadProductVersion());
            if (!currentVersion.Equals(record.ProductVersion, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException(
                    $"Repository version {currentVersion} differs from rollout version {record.ProductVersion}.");
            }

            var installerPath = installerOption is { Length: > 0 }
                ? Path.GetFullPath(installerOption)
                : Path.GetFullPath(Path.Combine(Root, record.InstallerPath.Replace('/', Path.DirectorySeparatorChar)));
            var installerHash = ComputeAndValidateInstallerHash(installerPath);
            if (!installerHash.Equals(record.InstallerSha256, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException("The installer differs from the approved rollout artifact.");
            }
            if (!record.Status.Equals("ReadyForProduction", StringComparison.Ordinal))
            {
                throw new InvalidDataException(
                    $"Rollout status is {record.Status}, not ReadyForProduction.");
            }

            return Passed(
                name,
                "Production readiness",
                $"v{record.ProductVersion} is bound to commit {record.RepositoryCommit} and installer SHA-256 {record.InstallerSha256} with {record.Gates.Count} passed gates and three current approvals.");
        }
        catch (Exception exception)
        {
            return Failed(name, "Production readiness", exception.Message);
        }
    }

    private static async Task<CertificationCheck> ValidateExperienceRecordAsync(
        string recordPath,
        ExperienceRecord experience,
        string installerOption)
    {
        const string name = "End-to-end customer experience gateway";
        try
        {
            if (experience.SchemaVersion != 1 ||
                !experience.Environment.Equals("sandbox", StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException(
                    "The E2E record must use schema version 1 and the sandbox environment.");
            }
            if (!Regex.IsMatch(experience.RunId, @"^e2e-[A-Za-z0-9._-]{4,80}$") ||
                !Guid.TryParse(experience.CorrelationId, out var correlationId) ||
                correlationId == Guid.Empty)
            {
                throw new InvalidDataException(
                    "The E2E record requires a valid run ID and journey correlation ID.");
            }
            var actualIds = experience.Journeys.Select(item => item.Id).ToArray();
            if (actualIds.Distinct(StringComparer.OrdinalIgnoreCase).Count() != actualIds.Length ||
                !ExperienceJourneyIds.ToHashSet(StringComparer.OrdinalIgnoreCase).SetEquals(actualIds))
            {
                throw new InvalidDataException(
                    "The E2E record does not contain the exact 15 required customer journeys.");
            }
            var incomplete = experience.Journeys
                .Where(item => !item.Status.Equals("Passed", StringComparison.Ordinal))
                .Select(item => item.Id)
                .ToArray();
            if (incomplete.Length > 0)
            {
                throw new InvalidDataException(
                    "Customer-experience gateway is blocked by: " + string.Join(", ", incomplete));
            }
            foreach (var journey in experience.Journeys)
            {
                if (journey.Evidence.Count == 0)
                {
                    throw new InvalidDataException(
                        $"Customer journey '{journey.Id}' has no evidence.");
                }
                foreach (var evidence in journey.Evidence)
                {
                    ValidateEvidenceReference(evidence, recordPath);
                }
            }
            if (experience.CompletedAtUtc is null ||
                experience.CompletedAtUtc < experience.StartedAtUtc ||
                DateTimeOffset.UtcNow - experience.CompletedAtUtc > TimeSpan.FromDays(7))
            {
                throw new InvalidDataException(
                    "E2E completion time is missing, invalid, or older than seven days.");
            }
            foreach (var required in new Dictionary<string, string>
                     {
                         ["first-time tester"] = experience.FirstTimeTester,
                         ["test coordinator"] = experience.TestCoordinator,
                         ["Windows 11 Pro build"] = experience.Windows11ProBuild,
                         ["browser"] = experience.Browser,
                         ["test inbox"] = experience.TestInbox,
                         ["PayPal sandbox buyer"] = experience.PayPalSandboxBuyer
                     })
            {
                if (string.IsNullOrWhiteSpace(required.Value))
                {
                    throw new InvalidDataException($"The {required.Key} is required.");
                }
            }
            if (!experience.Windows11ProBuild.Contains(
                    "Windows 11 Pro",
                    StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException(
                    "The customer-experience run must identify a supported Windows 11 Pro build.");
            }
            if (experience.TestInbox.EndsWith(
                    "@example.invalid",
                    StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException(
                    "The customer-experience run requires a captured allowlisted test inbox.");
            }

            var currentCommit = await GitValueAsync("rev-parse", "HEAD");
            if (!currentCommit.Equals(
                    experience.RepositoryCommit,
                    StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException(
                    "The E2E record is bound to a different Git commit.");
            }
            var currentVersion = NormalizeVersion(ReadProductVersion());
            if (!currentVersion.Equals(
                    NormalizeVersion(experience.ProductVersion),
                    StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException(
                    "The E2E record is bound to a different product version.");
            }
            var installerHash = ComputeAndValidateInstallerHash(Path.GetFullPath(installerOption));
            if (!installerHash.Equals(
                    experience.InstallerSha256,
                    StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException(
                    "The E2E record is bound to a different installer.");
            }

            var approvals = new[]
            {
                experience.Approvals.FirstTimeTester,
                experience.Approvals.TestCoordinator,
                experience.Approvals.ReleaseOwner
            };
            if (approvals.Any(approval => approval is null))
            {
                throw new InvalidDataException(
                    "First-time tester, test coordinator, and release-owner approvals are required.");
            }
            foreach (var approval in approvals.Cast<ExperienceApproval>())
            {
                if (approval.ApprovedAtUtc < experience.CompletedAtUtc ||
                    !approval.Commit.Equals(experience.RepositoryCommit, StringComparison.OrdinalIgnoreCase) ||
                    !approval.InstallerSha256.Equals(experience.InstallerSha256, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidDataException(
                        $"The {approval.Role} approval is stale or bound to another artifact.");
                }
            }
            return Passed(
                name,
                "Customer experience",
                $"Validated 15 complete sandbox customer journeys for v{currentVersion}, commit {currentCommit}, and installer SHA-256 {installerHash}.");
        }
        catch (Exception exception)
        {
            return Failed(name, "Customer experience", exception.Message);
        }
    }

    private static ExperienceRecord ReadExperienceRecord(string path)
    {
        if (!File.Exists(path))
        {
            throw new FileNotFoundException("The E2E customer-experience record was not found.", path);
        }
        return JsonSerializer.Deserialize<ExperienceRecord>(
                   File.ReadAllText(path),
                   new JsonSerializerOptions { PropertyNameCaseInsensitive = true })
               ?? throw new InvalidDataException("The E2E customer-experience record is invalid.");
    }

    private static void ValidateCustomerExperienceEvidence(
        RolloutGate gate,
        string rolloutRecordPath,
        RolloutRecord rollout)
    {
        foreach (var evidence in gate.Evidence)
        {
            if (Uri.TryCreate(evidence, UriKind.Absolute, out _))
            {
                continue;
            }
            var evidencePath = Path.GetFullPath(Path.Combine(
                Path.GetDirectoryName(rolloutRecordPath)!,
                evidence.Replace('/', Path.DirectorySeparatorChar)));
            if (Directory.Exists(evidencePath))
            {
                evidencePath = Path.Combine(evidencePath, "report.json");
            }
            if (!File.Exists(evidencePath) ||
                !Path.GetFileName(evidencePath).Equals(
                    "report.json",
                    StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }
            using var document = JsonDocument.Parse(File.ReadAllText(evidencePath));
            var root = document.RootElement;
            if (root.GetProperty("Status").GetString() == "Passed" &&
                root.GetProperty("Environment").GetString() == "customer-experience" &&
                root.GetProperty("RepositoryCommit").GetString() == rollout.RepositoryCommit &&
                NormalizeVersion(root.GetProperty("ProductVersion").GetString() ?? "") ==
                rollout.ProductVersion &&
                root.GetProperty("ArtifactSha256").GetString() == rollout.InstallerSha256)
            {
                return;
            }
        }
        throw new InvalidDataException(
            "The manual-customer gate requires a local passing e2e-verify report.json bound to the same version, commit, and installer.");
    }

    private static string ComputeAndValidateInstallerHash(string installerPath)
    {
        if (!File.Exists(installerPath))
        {
            throw new FileNotFoundException("The release-candidate installer was not found.", installerPath);
        }
        var checksumPath = installerPath + ".sha256";
        if (!File.Exists(checksumPath))
        {
            throw new FileNotFoundException("The installer checksum file was not found.", checksumPath);
        }
        var expected = Regex.Match(File.ReadAllText(checksumPath), @"\b[0-9a-fA-F]{64}\b").Value;
        using var installer = File.OpenRead(installerPath);
        var actual = Convert.ToHexString(SHA256.HashData(installer)).ToLowerInvariant();
        if (expected.Length != 64 || !actual.Equals(expected, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidDataException("The release-candidate installer does not match its checksum.");
        }
        return actual;
    }

    private static string NormalizeVersion(string version)
    {
        var normalized = version.Trim().TrimStart('v', 'V');
        if (!Regex.IsMatch(normalized, @"^\d+\.\d+\.\d+$"))
        {
            throw new ArgumentException($"Invalid release version '{version}'.");
        }
        return normalized;
    }

    private static RolloutRecord ReadRolloutRecord(string path)
    {
        if (!File.Exists(path))
        {
            throw new FileNotFoundException("The rollout record was not found.", path);
        }
        return JsonSerializer.Deserialize<RolloutRecord>(
                   File.ReadAllText(path),
                   new JsonSerializerOptions { PropertyNameCaseInsensitive = true })
               ?? throw new InvalidDataException("The rollout record is invalid.");
    }

    private static void WriteRolloutRecord(string path, RolloutRecord record)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        var temporary = path + ".tmp";
        File.WriteAllText(
            temporary,
            JsonSerializer.Serialize(record, JsonOptions) + Environment.NewLine,
            Encoding.UTF8);
        File.Move(temporary, path, true);
    }

    private static void ValidateEvidenceReference(string evidence, string recordPath)
    {
        if (Uri.TryCreate(evidence, UriKind.Absolute, out var uri))
        {
            if (uri.Scheme != Uri.UriSchemeHttps)
            {
                throw new InvalidDataException("Evidence URLs must use HTTPS.");
            }
            return;
        }
        var baseDirectory = Path.GetDirectoryName(recordPath)!;
        var path = Path.GetFullPath(Path.Combine(baseDirectory, evidence.Replace('/', Path.DirectorySeparatorChar)));
        if (!File.Exists(path) && !Directory.Exists(path))
        {
            throw new FileNotFoundException($"Rollout evidence does not exist: {evidence}", path);
        }
    }

    private static void InvalidateApprovals(RolloutRecord record)
    {
        record.Approvals = new RolloutApprovals();
    }

    private static bool AllApprovalsPresent(RolloutRecord record) =>
        record.Approvals.ReleaseOwner is not null &&
        record.Approvals.TestOwner is not null &&
        record.Approvals.RollbackOwner is not null;

    private static async Task RunLocalGateAsync(
        CertificationReport report,
        IReadOnlyDictionary<string, string> options)
    {
        report.Checks.Add(await RunProcessCheckAsync(
            "Viewer dependency restore",
            "Build",
            OperatingSystem.IsWindows() ? "pnpm.cmd" : "pnpm",
            ["install", "--frozen-lockfile"],
            Path.Combine(Root, "src", "ReceiptEmulator.Viewer")));
        report.Checks.Add(await RunProcessCheckAsync(
            "Viewer production build",
            "Build",
            OperatingSystem.IsWindows() ? "pnpm.cmd" : "pnpm",
            ["run", "build"],
            Path.Combine(Root, "src", "ReceiptEmulator.Viewer")));
        foreach (var project in new[]
                 {
                     Path.Combine(Root, "src", "ReceiptEmulator.App", "ReceiptEmulator.App.csproj"),
                     Path.Combine(Root, "src", "POSPrinterEmulator.Desktop", "POSPrinterEmulator.Desktop.csproj"),
                     Path.Combine(Root, "src", "POSPrinterEmulator.Updater", "POSPrinterEmulator.Updater.csproj"),
                     Path.Combine(Root, "tools", "POSPrinterEmulator.DatabaseTool", "POSPrinterEmulator.DatabaseTool.csproj")
                 })
        {
            report.Checks.Add(await RunProcessCheckAsync(
                $"Release build: {Path.GetFileNameWithoutExtension(project)}",
                "Build",
                "dotnet",
                ["build", project, "--configuration", "Release", "--nologo"]));
        }

        foreach (var sqlFile in new[]
                 {
                     Path.Combine(Root, "database", "schema.sql"),
                     Path.Combine(Root, "database", "migrate-journey-correlation.sql")
                 })
        {
            report.Checks.Add(await RunProcessCheckAsync(
                $"SQL statement validation: {Path.GetFileName(sqlFile)}",
                "Staging data",
                "dotnet",
                ["run", "--project",
                    Path.Combine(Root, "tools", "POSPrinterEmulator.DatabaseTool",
                        "POSPrinterEmulator.DatabaseTool.csproj"),
                    "--configuration", "Release", "--no-build", "--",
                    "validate-schema", sqlFile]));
        }

        report.Checks.Add(await RunProcessCheckAsync(
            "C# unit and component tests",
            "Automated service testing",
            "dotnet",
            ["test", Path.Combine(Root, "tests", "ReceiptEmulator.Tests", "ReceiptEmulator.Tests.csproj"),
                "--configuration", "Release", "--nologo"]));

        var phpTests = new[]
        {
            "admin-commerce-tests.php",
            "buy-commerce-tests.php",
            "communications-tests.php",
            "customer-crm-tests.php",
            "customer-portal-tests.php",
            "geography-tests.php",
            "main-website-contract-tests.php",
            "website-content-tests.php"
        };
        foreach (var test in phpTests)
        {
            report.Checks.Add(await RunProcessCheckAsync(
                $"PHP contract: {Path.GetFileNameWithoutExtension(test)}",
                "Automated service testing",
                "php",
                [Path.Combine(Root, "tests", "php", test)]));
        }

        report.Checks.Add(await RunProcessCheckAsync(
            "Release metadata synchronization",
            "Release integrity",
            "dotnet",
            ["run", "--project", Path.Combine(Root, "tools", "ReceiptLab.Build"),
                "--configuration", "Release", "--", "sync-release", "--check"]));
        report.Checks.Add(await RunProcessCheckAsync(
            "Public website SEO validation",
            "Website",
            "dotnet",
            ["run", "--project", Path.Combine(Root, "tools", "ReceiptLab.Build"),
                "--configuration", "Release", "--", "check-seo"]));

        report.Checks.Add(ValidateScenarioMatrix());
        report.Checks.Add(ScanCustomerSurfaces());
        report.Checks.Add(ValidateRolloutPolicy());

        if (options.TryGetValue("config", out var configPath))
        {
            report.Checks.Add(ValidateStagingConfiguration(Path.GetFullPath(configPath)));
        }
        if (options.TryGetValue("installer", out var installerPath))
        {
            report.Checks.Add(ValidateInstaller(Path.GetFullPath(installerPath)));
        }
    }

    private static async Task RunPreflightAsync(CertificationReport report, string configPath)
    {
        report.Checks.Add(ValidateStagingConfiguration(Path.GetFullPath(configPath)));
        report.Checks.Add(await ValidateSandboxProviderCredentialsAsync());
        report.Checks.Add(ValidateScenarioMatrix());
        report.Checks.Add(ScanCustomerSurfaces());
        report.Checks.Add(ValidateRolloutPolicy());
    }

    private static async Task<CertificationCheck> ValidateSandboxProviderCredentialsAsync()
    {
        const string name = "Sandbox provider credentials";
        try
        {
            var clientId = ReadEnvironmentVariable("PPE_CERT_PAYPAL_CLIENT_ID")
                ?? throw new InvalidDataException("The PayPal Sandbox client ID is missing.");
            var clientSecret = ReadEnvironmentVariable("PPE_CERT_PAYPAL_SECRET")
                ?? throw new InvalidDataException("The PayPal Sandbox REST secret is missing.");
            var brevoApiKey = ReadEnvironmentVariable("PPE_CERT_EMAIL_API_KEY")
                ?? throw new InvalidDataException("The Brevo transactional API key is missing.");
            var failures = new List<string>();

            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
            using var paypalRequest = new HttpRequestMessage(
                HttpMethod.Post,
                "https://api-m.sandbox.paypal.com/v1/oauth2/token")
            {
                Content = new StringContent(
                    "grant_type=client_credentials",
                    Encoding.UTF8,
                    "application/x-www-form-urlencoded")
            };
            paypalRequest.Headers.Authorization =
                new System.Net.Http.Headers.AuthenticationHeaderValue(
                    "Basic",
                    Convert.ToBase64String(Encoding.ASCII.GetBytes($"{clientId}:{clientSecret}")));
            using var paypalResponse = await client.SendAsync(paypalRequest);
            if (paypalResponse.StatusCode != HttpStatusCode.OK)
            {
                failures.Add(
                    $"PayPal Sandbox REST authentication returned HTTP {(int)paypalResponse.StatusCode}");
            }

            using var brevoRequest = new HttpRequestMessage(
                HttpMethod.Get,
                "https://api.brevo.com/v3/account");
            brevoRequest.Headers.Add("api-key", brevoApiKey);
            using var brevoResponse = await client.SendAsync(brevoRequest);
            if (brevoResponse.StatusCode != HttpStatusCode.OK)
            {
                failures.Add(
                    $"Brevo transactional API authentication returned HTTP {(int)brevoResponse.StatusCode}");
            }
            if (failures.Count > 0)
            {
                throw new InvalidDataException(string.Join("; ", failures) + ".");
            }

            return Passed(
                name,
                "Staging",
                "PayPal Sandbox REST and Brevo transactional API credentials authenticated successfully.");
        }
        catch (Exception exception)
        {
            return Failed(name, "Staging", exception.Message);
        }
    }

    private static CertificationCheck ValidateStagingConfiguration(string path)
    {
        const string name = "Isolated staging configuration";
        try
        {
            if (!File.Exists(path))
            {
                throw new FileNotFoundException("The staging configuration file was not found.", path);
            }

            using var document = JsonDocument.Parse(File.ReadAllText(path));
            var root = document.RootElement;
            if (!root.TryGetProperty("environment", out var environment) ||
                !environment.GetString()!.Equals("staging", StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException("The environment must be explicitly set to staging.");
            }
            if (!root.TryGetProperty("allowProductionHosts", out var allowProduction) ||
                allowProduction.ValueKind != JsonValueKind.False)
            {
                throw new InvalidDataException("allowProductionHosts must be false.");
            }

            var productionHosts = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
            {
                "www.posprinteremulator.com",
                "posprinteremulator.com",
                "userportal.posprinteremulator.com",
                "admin.posprinteremulator.com",
                "buy.posprinteremulator.com",
                "support.posprinteremulator.com",
                "api-m.paypal.com"
            };
            var urls = root.GetProperty("urls");
            foreach (var requiredUrl in new[]
                     {
                         "website", "customerPortal", "adminPortal", "buyWebsite",
                         "supportPortal", "licensingService", "paypalApi"
                     })
            {
                if (!urls.TryGetProperty(requiredUrl, out _))
                {
                    throw new InvalidDataException($"{requiredUrl} staging URL is required.");
                }
            }
            var checkedUrls = 0;
            foreach (var property in urls.EnumerateObject())
            {
                if (!Uri.TryCreate(property.Value.GetString(), UriKind.Absolute, out var uri) ||
                    uri.Scheme != Uri.UriSchemeHttps)
                {
                    throw new InvalidDataException($"{property.Name} must be an absolute HTTPS URL.");
                }
                if (productionHosts.Contains(uri.Host))
                {
                    throw new InvalidDataException($"{property.Name} points to a production host.");
                }
                checkedUrls++;
            }
            if (checkedUrls < 7)
            {
                throw new InvalidDataException(
                    "Website, customer portal, admin, buy, support, licensing, and PayPal staging URLs are required.");
            }

            if (!root.TryGetProperty("webspaceDirectories", out var directories) ||
                directories.ValueKind != JsonValueKind.Object)
            {
                throw new InvalidDataException("The isolated webspace directory map is required.");
            }
            var directoryNames = new List<string>();
            foreach (var requiredDirectory in new[]
                     {
                         "website", "customerPortal", "adminPortal", "buyWebsite", "supportPortal"
                     })
            {
                if (!directories.TryGetProperty(requiredDirectory, out var directoryValue) ||
                    string.IsNullOrWhiteSpace(directoryValue.GetString()))
                {
                    throw new InvalidDataException($"{requiredDirectory} webspace directory is required.");
                }
                directoryNames.Add(directoryValue.GetString()!.Trim());
            }
            if (directoryNames.Distinct(StringComparer.OrdinalIgnoreCase).Count() != directoryNames.Count)
            {
                throw new InvalidDataException("Every sandbox host must use a distinct webspace directory.");
            }

            var missingSecrets = root.GetProperty("requiredEnvironmentVariables")
                .EnumerateArray()
                .Select(item => item.GetString() ?? string.Empty)
                .Where(name => name.Length == 0 || string.IsNullOrWhiteSpace(ReadEnvironmentVariable(name)))
                .ToArray();
            if (missingSecrets.Length > 0)
            {
                throw new InvalidDataException(
                    "Protected staging variables are missing: " + string.Join(", ", missingSecrets));
            }

            return Passed(
                name,
                "Staging",
                $"Validated {checkedUrls} isolated HTTPS services, {directoryNames.Count} webspace directories, and protected variable names.");
        }
        catch (Exception exception)
        {
            return Failed(name, "Staging", exception.Message);
        }
    }

    private static CertificationCheck ValidateScenarioMatrix()
    {
        const string name = "Customer journey scenario matrix";
        try
        {
            var path = Path.Combine(Root, "certification", "scenarios.json");
            using var document = JsonDocument.Parse(File.ReadAllText(path));
            var scenarios = document.RootElement.GetProperty("scenarios").EnumerateArray().ToArray();
            var ids = scenarios.Select(item => item.GetProperty("id").GetString() ?? string.Empty).ToArray();
            var duplicates = ids.GroupBy(id => id, StringComparer.OrdinalIgnoreCase)
                .Where(group => group.Count() > 1)
                .Select(group => group.Key)
                .ToArray();
            if (duplicates.Length > 0)
            {
                throw new InvalidDataException("Duplicate scenario IDs: " + string.Join(", ", duplicates));
            }
            foreach (var required in new[] { "trial-to-lite", "trial-to-pro", "trial-to-enterprise" })
            {
                if (!ids.Contains(required, StringComparer.OrdinalIgnoreCase))
                {
                    throw new InvalidDataException($"Required journey '{required}' is missing.");
                }
            }

            var critical = scenarios.Count(item =>
                item.TryGetProperty("critical", out var value) && value.ValueKind == JsonValueKind.True);
            return Passed(name, "Test matrix", $"Validated {scenarios.Length} scenarios, including {critical} critical journeys.");
        }
        catch (Exception exception)
        {
            return Failed(name, "Test matrix", exception.Message);
        }
    }

    private static string? ReadEnvironmentVariable(string name)
    {
        var processValue = Environment.GetEnvironmentVariable(name);
        if (!string.IsNullOrWhiteSpace(processValue) || !OperatingSystem.IsWindows())
        {
            return processValue;
        }

        return Environment.GetEnvironmentVariable(name, EnvironmentVariableTarget.User);
    }

    private static CertificationCheck ScanCustomerSurfaces()
    {
        const string name = "Customer-facing activation-key absence";
        var roots = new[]
        {
            Path.Combine(Root, "website"),
            Path.Combine(Root, "customer-portal"),
            Path.Combine(Root, "buy-website"),
            Path.Combine(Root, "installer"),
            Path.Combine(Root, "src", "ReceiptEmulator.Viewer", "src"),
            Path.Combine(Root, "src", "ReceiptEmulator.App", "wwwroot")
        };
        var extensions = new HashSet<string>(
            [".html", ".php", ".js", ".ts", ".tsx", ".json", ".txt"],
            StringComparer.OrdinalIgnoreCase);
        var forbidden = new Regex(
            @"activation[\s-]*key|resend[\s-]*activation|backup[\s-]*activation|PPE1-",
            RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
        var matches = new List<string>();

        foreach (var root in roots.Where(Directory.Exists))
        {
            foreach (var file in Directory.EnumerateFiles(root, "*", SearchOption.AllDirectories)
                         .Where(file => extensions.Contains(Path.GetExtension(file)))
                         .Where(file => !file.Contains($"{Path.DirectorySeparatorChar}downloads{Path.DirectorySeparatorChar}",
                             StringComparison.OrdinalIgnoreCase))
                         .Where(file => !file.Contains($"{Path.DirectorySeparatorChar}private{Path.DirectorySeparatorChar}",
                             StringComparison.OrdinalIgnoreCase)))
            {
                var lineNumber = 0;
                foreach (var line in File.ReadLines(file))
                {
                    lineNumber++;
                    if (forbidden.IsMatch(line))
                    {
                        matches.Add($"{Path.GetRelativePath(Root, file)}:{lineNumber}");
                    }
                }
            }
        }

        return matches.Count == 0
            ? Passed(name, "Keyless licensing", "No prohibited activation-key language was found on customer surfaces.")
            : Failed(name, "Keyless licensing",
                $"Found prohibited language at {string.Join(", ", matches.Take(20))}" +
                (matches.Count > 20 ? $" and {matches.Count - 20} more." : "."));
    }

    private static CertificationCheck ValidateInstaller(string installerPath)
    {
        const string name = "Release-candidate installer integrity";
        try
        {
            if (!File.Exists(installerPath))
            {
                throw new FileNotFoundException("The release-candidate installer was not found.", installerPath);
            }
            var checksumPath = installerPath + ".sha256";
            if (!File.Exists(checksumPath))
            {
                throw new FileNotFoundException("The installer checksum file was not found.", checksumPath);
            }

            var expected = Regex.Match(File.ReadAllText(checksumPath), @"\b[0-9a-fA-F]{64}\b").Value;
            using var installer = File.OpenRead(installerPath);
            var actual = Convert.ToHexString(SHA256.HashData(installer)).ToLowerInvariant();
            if (!actual.Equals(expected, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException("The release-candidate installer does not match its checksum.");
            }

            return Passed(name, "Installer", $"{Path.GetFileName(installerPath)} matched SHA-256 {actual}.");
        }
        catch (Exception exception)
        {
            return Failed(name, "Installer", exception.Message);
        }
    }

    private static CertificationCheck ValidateRolloutPolicy()
    {
        const string name = "Sandbox-first rollout enforcement";
        try
        {
            if (RolloutGateDefinitions.Length != 18 ||
                RolloutGateDefinitions.Select(item => item.Id)
                    .Distinct(StringComparer.OrdinalIgnoreCase).Count() != RolloutGateDefinitions.Length ||
                RolloutGateDefinitions.Any(item => !item.Critical))
            {
                throw new InvalidDataException(
                    "The rollout policy must contain 18 unique critical gates.");
            }
            var checklistPath = Path.Combine(
                Root,
                "certification",
                "SANDBOX_ROLLOUT_CHECKLIST.md");
            var automationPath = Path.Combine(
                Root,
                "certification",
                "ROLLOUT_AUTOMATION.md");
            var experienceChecklistPath = Path.Combine(
                Root,
                "certification",
                "E2E_CUSTOMER_EXPERIENCE_CHECKLIST.md");
            var experienceExamplePath = Path.Combine(
                Root,
                "certification",
                "e2e-experience.example.json");
            var publisherPath = Path.Combine(
                Root,
                "tools",
                "POSPrinterEmulator.WebsitePublisher",
                "Program.cs");
            foreach (var path in new[]
                     {
                         checklistPath,
                         automationPath,
                         experienceChecklistPath,
                         experienceExamplePath,
                         publisherPath
                     })
            {
                if (!File.Exists(path))
                {
                    throw new FileNotFoundException("Required rollout policy file is missing.", path);
                }
            }
            var checklist = File.ReadAllText(checklistPath);
            for (var section = 1; section <= 18; section++)
            {
                if (!checklist.Contains($"## {section}.", StringComparison.Ordinal))
                {
                    throw new InvalidDataException(
                        $"Sandbox rollout checklist section {section} is missing.");
                }
            }
            var automation = File.ReadAllText(automationPath);
            foreach (var command in new[]
                     {
                         "rollout-start",
                         "rollout-set",
                         "rollout-approve",
                         "rollout-verify"
                     })
            {
                if (!automation.Contains(command, StringComparison.Ordinal))
                {
                    throw new InvalidDataException(
                        $"Rollout automation documentation is missing {command}.");
                }
            }
            using (var example = JsonDocument.Parse(File.ReadAllText(experienceExamplePath)))
            {
                var ids = example.RootElement.GetProperty("journeys")
                    .EnumerateArray()
                    .Select(item => item.GetProperty("id").GetString() ?? "")
                    .ToArray();
                if (!ExperienceJourneyIds.ToHashSet(StringComparer.OrdinalIgnoreCase).SetEquals(ids))
                {
                    throw new InvalidDataException(
                        "The E2E example record does not contain the required customer journeys.");
                }
            }
            var publisher = File.ReadAllText(publisherPath);
            if (!publisher.Contains("PPE_ROLLOUT_READINESS_REPORT", StringComparison.Ordinal) ||
                !publisher.Contains("RequireProductionReadiness", StringComparison.Ordinal))
            {
                throw new InvalidDataException(
                    "Production publisher readiness enforcement is missing.");
            }
            if (!publisher.Contains("IsGeneratedReleaseDownload", StringComparison.Ordinal) ||
                !publisher.Contains(".exe.sha256", StringComparison.Ordinal))
            {
                throw new InvalidDataException(
                    "Website publishing must exclude generated installer downloads from recursive site synchronization.");
            }
            if (!publisher.Contains("run-sandbox-communications-cron", StringComparison.Ordinal) ||
                !publisher.Contains("admin_sandbox_posprinteremulator", StringComparison.Ordinal))
            {
                throw new InvalidDataException(
                    "Verified-host sandbox communications cron diagnostics are missing.");
            }
            return Passed(
                name,
                "Production readiness",
                "Validated 18 critical rollout gates, 15 E2E customer journeys, documentation, production publisher enforcement, generated-download exclusion, and verified-host sandbox cron diagnostics.");
        }
        catch (Exception exception)
        {
            return Failed(name, "Production readiness", exception.Message);
        }
    }

    private static async Task<CertificationCheck> RunProcessCheckAsync(
        string name,
        string category,
        string executable,
        IReadOnlyList<string> arguments,
        string? workingDirectory = null)
    {
        var started = DateTimeOffset.UtcNow;
        try
        {
            var usesCommandScript = OperatingSystem.IsWindows() &&
                                    (executable.EndsWith(".cmd", StringComparison.OrdinalIgnoreCase) ||
                                     executable.EndsWith(".bat", StringComparison.OrdinalIgnoreCase));
            var startInfo = new ProcessStartInfo(
                usesCommandScript
                    ? Environment.GetEnvironmentVariable("ComSpec") ?? "cmd.exe"
                    : executable)
            {
                WorkingDirectory = workingDirectory ?? Root,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                UseShellExecute = false,
                CreateNoWindow = true
            };
            if (usesCommandScript)
            {
                startInfo.Arguments =
                    "/d /c " + executable + " " +
                    string.Join(" ", arguments.Select(QuoteForCommand));
            }
            else
            {
                foreach (var argument in arguments)
                {
                    startInfo.ArgumentList.Add(argument);
                }
            }

            using var process = Process.Start(startInfo)
                ?? throw new InvalidOperationException($"Could not start {executable}.");
            var standardOutput = process.StandardOutput.ReadToEndAsync();
            var standardError = process.StandardError.ReadToEndAsync();
            await process.WaitForExitAsync();
            var output = (await standardOutput) + (await standardError);
            var duration = DateTimeOffset.UtcNow - started;
            return new CertificationCheck
            {
                Name = name,
                Category = category,
                Critical = true,
                Status = process.ExitCode == 0 ? "Passed" : "Failed",
                Summary = process.ExitCode == 0
                    ? $"Completed in {duration.TotalSeconds:N1} seconds."
                    : $"Exited with code {process.ExitCode}.",
                DurationMilliseconds = (long)duration.TotalMilliseconds,
                Details = TruncateAndRedact(output)
            };
        }
        catch (Exception exception)
        {
            return Failed(name, category, exception.Message);
        }
    }

    private static void WriteReport(string directory, CertificationReport report)
    {
        Directory.CreateDirectory(directory);
        File.WriteAllText(
            Path.Combine(directory, "report.json"),
            JsonSerializer.Serialize(report, JsonOptions) + Environment.NewLine,
            Encoding.UTF8);

        var rows = string.Join(Environment.NewLine, report.Checks.Select(check =>
            $"<tr><td>{WebUtility.HtmlEncode(check.Status)}</td>" +
            $"<td>{WebUtility.HtmlEncode(check.Category)}</td>" +
            $"<td>{WebUtility.HtmlEncode(check.Name)}</td>" +
            $"<td>{WebUtility.HtmlEncode(check.Summary)}</td></tr>"));
        var html = $$"""
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
            <title>POS Printer Emulator Certification {{WebUtility.HtmlEncode(report.RunId)}}</title>
            <style>body{font:15px/1.5 Segoe UI,sans-serif;max-width:1200px;margin:40px auto;padding:0 24px;color:#10233d}
            table{width:100%;border-collapse:collapse}th,td{padding:10px;border:1px solid #ccd8e6;text-align:left}
            th{background:#071c32;color:white}.Passed{color:#087443}.Failed{color:#b42318}code{overflow-wrap:anywhere}</style></head>
            <body><h1>Release certification: <span class="{{report.Status}}">{{report.Status}}</span></h1>
            <dl><dt>Run</dt><dd><code>{{WebUtility.HtmlEncode(report.RunId)}}</code></dd>
            <dt>Correlation ID</dt><dd><code>{{WebUtility.HtmlEncode(report.CorrelationId)}}</code></dd>
            <dt>Version</dt><dd>{{WebUtility.HtmlEncode(report.ProductVersion)}}</dd>
            <dt>Commit</dt><dd><code>{{WebUtility.HtmlEncode(report.RepositoryCommit)}}</code></dd>
            <dt>Artifact SHA-256</dt><dd><code>{{WebUtility.HtmlEncode(report.ArtifactSha256)}}</code></dd>
            <dt>Environment</dt><dd>{{WebUtility.HtmlEncode(report.Environment)}}</dd></dl>
            <table><thead><tr><th>Status</th><th>Category</th><th>Check</th><th>Result</th></tr></thead>
            <tbody>{{rows}}</tbody></table></body></html>
            """;
        File.WriteAllText(Path.Combine(directory, "report.html"), html, Encoding.UTF8);
    }

    private static string TruncateAndRedact(string value)
    {
        var redacted = Regex.Replace(value, @"(?i)(password|secret|token|client[_ -]?id)\s*[:=]\s*\S+", "$1=[REDACTED]");
        return redacted.Length <= 30_000 ? redacted : redacted[..30_000] + Environment.NewLine + "[output truncated]";
    }

    private static string QuoteForCommand(string value) =>
        "\"" + value.Replace("\"", "\"\"", StringComparison.Ordinal) + "\"";

    private static CertificationCheck Passed(string name, string category, string summary) =>
        new() { Name = name, Category = category, Critical = true, Status = "Passed", Summary = summary };

    private static CertificationCheck Failed(string name, string category, string summary) =>
        new() { Name = name, Category = category, Critical = true, Status = "Failed", Summary = summary };

    private static string ReadProductVersion()
    {
        var path = Path.Combine(Root, "website", "release.json");
        using var document = JsonDocument.Parse(File.ReadAllText(path));
        return document.RootElement.GetProperty("currentVersion").GetString() ?? "unknown";
    }

    private static async Task<string> GitValueAsync(params string[] arguments)
    {
        try
        {
            var startInfo = new ProcessStartInfo("git")
            {
                WorkingDirectory = Root,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                UseShellExecute = false,
                CreateNoWindow = true
            };
            foreach (var argument in arguments) startInfo.ArgumentList.Add(argument);
            using var process = Process.Start(startInfo)!;
            var value = await process.StandardOutput.ReadToEndAsync();
            await process.WaitForExitAsync();
            return process.ExitCode == 0 ? value.Trim() : "unknown";
        }
        catch
        {
            return "unknown";
        }
    }

    private static Dictionary<string, string> ParseOptions(string[] args)
    {
        var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        for (var index = 0; index < args.Length; index++)
        {
            if (!args[index].StartsWith("--", StringComparison.Ordinal))
            {
                throw new ArgumentException($"Unexpected argument '{args[index]}'.");
            }
            var key = args[index][2..];
            if (index + 1 >= args.Length || args[index + 1].StartsWith("--", StringComparison.Ordinal))
            {
                result[key] = "true";
            }
            else
            {
                result[key] = args[++index];
            }
        }
        return result;
    }

    private static string RequireOption(IReadOnlyDictionary<string, string> options, string key) =>
        options.TryGetValue(key, out var value) && value != "true"
            ? value
            : throw new ArgumentException($"--{key} is required.");

    private static string FindRepositoryRoot()
    {
        foreach (var start in new[] { Environment.CurrentDirectory, AppContext.BaseDirectory })
        {
            var directory = new DirectoryInfo(start);
            while (directory is not null)
            {
                var gitMarker = Path.Combine(directory.FullName, ".git");
                if ((Directory.Exists(gitMarker) || File.Exists(gitMarker)) &&
                    File.Exists(Path.Combine(directory.FullName, "website", "release.json")))
                {
                    return directory.FullName;
                }
                directory = directory.Parent;
            }
        }
        throw new DirectoryNotFoundException("The POS Printer Emulator repository root could not be located.");
    }

    private static void PrintHelp()
    {
        Console.WriteLine("""
            POS Printer Emulator release certification

            Commands:
              local-gate [--config FILE] [--installer FILE] [--report-directory DIR]
              preflight --config FILE [--report-directory DIR]
              scan-customer-surfaces [--report-directory DIR]
              validate-matrix [--report-directory DIR]
              validate-rollout-policy [--report-directory DIR]
              rollout-start --version VERSION --installer FILE [--record FILE] [--actor NAME]
              rollout-set --record FILE --gate ID --status STATUS --evidence PATH_OR_HTTPS_URL [--notes TEXT] [--actor NAME]
              rollout-approve --record FILE --role ROLE --name NAME
              rollout-verify --record FILE [--installer FILE] [--report-directory DIR]
              e2e-verify --record FILE --installer FILE [--report-directory DIR]
              desktop-profile-install [--source FILE] [--destination FILE]
              desktop-profile-verify [--destination FILE]
              desktop-profile-remove --confirm REMOVE-CERTIFICATION-PROFILE [--destination FILE]

            Secrets are read only from environment variables named by the staging configuration.
            Reports never include secret values.
            Production readiness fails closed unless every gate passes, evidence exists,
            three current approvals are recorded, and commit/version/installer integrity match.
            """);
    }
}

internal sealed class CertificationReport
{
    public string RunId { get; set; } = "";
    public string CorrelationId { get; set; } = "";
    public string Status { get; set; } = "Running";
    public string Environment { get; set; } = "";
    public string ProductVersion { get; set; } = "";
    public string ArtifactSha256 { get; set; } = "";
    public string RepositoryCommit { get; set; } = "";
    public string RepositoryBranch { get; set; } = "";
    public DateTimeOffset StartedAtUtc { get; set; }
    public DateTimeOffset CompletedAtUtc { get; set; }
    public List<CertificationCheck> Checks { get; set; } = [];
}

internal sealed class CertificationCheck
{
    public string Name { get; set; } = "";
    public string Category { get; set; } = "";
    public bool Critical { get; set; }
    public string Status { get; set; } = "";
    public string Summary { get; set; } = "";
    public long DurationMilliseconds { get; set; }
    public string Details { get; set; } = "";
}

internal sealed class RolloutRecord
{
    public int SchemaVersion { get; set; }
    public string RolloutId { get; set; } = "";
    public string ProductVersion { get; set; } = "";
    public string RepositoryCommit { get; set; } = "";
    public string InstallerPath { get; set; } = "";
    public string InstallerSha256 { get; set; } = "";
    public string Status { get; set; } = "Draft";
    public string CreatedBy { get; set; } = "";
    public DateTimeOffset CreatedAtUtc { get; set; }
    public DateTimeOffset GateUpdatedAtUtc { get; set; }
    public List<RolloutGate> Gates { get; set; } = [];
    public RolloutApprovals Approvals { get; set; } = new();
    public List<RolloutEvent> Events { get; set; } = [];
}

internal sealed class RolloutGate
{
    public string Id { get; set; } = "";
    public string Title { get; set; } = "";
    public bool Critical { get; set; }
    public string Status { get; set; } = "Pending";
    public List<string> Evidence { get; set; } = [];
    public string Notes { get; set; } = "";
    public string UpdatedBy { get; set; } = "";
    public DateTimeOffset? UpdatedAtUtc { get; set; }
}

internal sealed class RolloutApprovals
{
    public RolloutApproval? ReleaseOwner { get; set; }
    public RolloutApproval? TestOwner { get; set; }
    public RolloutApproval? RollbackOwner { get; set; }
}

internal sealed class RolloutApproval
{
    public string Role { get; set; } = "";
    public string Name { get; set; } = "";
    public DateTimeOffset ApprovedAtUtc { get; set; }
    public string Commit { get; set; } = "";
    public string InstallerSha256 { get; set; } = "";
}

internal sealed class RolloutEvent
{
    public DateTimeOffset OccurredAtUtc { get; set; }
    public string Actor { get; set; } = "";
    public string Action { get; set; } = "";
    public string GateId { get; set; } = "";
    public string Summary { get; set; } = "";
}

internal sealed class ExperienceRecord
{
    public int SchemaVersion { get; set; }
    public string Environment { get; set; } = "";
    public string RunId { get; set; } = "";
    public string CorrelationId { get; set; } = "";
    public string ProductVersion { get; set; } = "";
    public string RepositoryCommit { get; set; } = "";
    public string InstallerSha256 { get; set; } = "";
    public DateTimeOffset StartedAtUtc { get; set; }
    public DateTimeOffset? CompletedAtUtc { get; set; }
    public string FirstTimeTester { get; set; } = "";
    public string TestCoordinator { get; set; } = "";
    public string Windows11ProBuild { get; set; } = "";
    public string Browser { get; set; } = "";
    public string TestInbox { get; set; } = "";
    public string PayPalSandboxBuyer { get; set; } = "";
    public List<ExperienceJourney> Journeys { get; set; } = [];
    public ExperienceApprovals Approvals { get; set; } = new();
}

internal sealed class ExperienceJourney
{
    public string Id { get; set; } = "";
    public string Status { get; set; } = "Pending";
    public List<string> Evidence { get; set; } = [];
    public string Notes { get; set; } = "";
}

internal sealed class ExperienceApprovals
{
    public ExperienceApproval? FirstTimeTester { get; set; }
    public ExperienceApproval? TestCoordinator { get; set; }
    public ExperienceApproval? ReleaseOwner { get; set; }
}

internal sealed class ExperienceApproval
{
    public string Role { get; set; } = "";
    public string Name { get; set; } = "";
    public DateTimeOffset ApprovedAtUtc { get; set; }
    public string Commit { get; set; } = "";
    public string InstallerSha256 { get; set; } = "";
}
