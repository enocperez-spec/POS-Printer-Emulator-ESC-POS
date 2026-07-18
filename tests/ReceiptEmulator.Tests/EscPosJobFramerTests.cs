using ReceiptEmulator;

namespace ReceiptEmulator.Tests;

public sealed class EscPosJobFramerTests
{
    [Fact]
    public void ExtractsSequentialCutDelimitedJobsAndLeavesPartialTail()
    {
        var buffer = new List<byte>
        {
            0x41, 0x0A, 0x1D, 0x56, 0x00,
            0x42, 0x0A, 0x1D, 0x56, 0x42, 0x18,
            0x43
        };

        var jobs = EscPosJobFramer.ExtractCutJobs(buffer);

        Assert.Equal(2, jobs.Count);
        Assert.Equal(new byte[] { 0x41, 0x0A, 0x1D, 0x56, 0x00 }, jobs[0]);
        Assert.Equal(new byte[] { 0x42, 0x0A, 0x1D, 0x56, 0x42, 0x18 }, jobs[1]);
        Assert.Equal(new byte[] { 0x43 }, buffer);
    }

    [Fact]
    public void WaitsForTheFinalFeedByteOfFourByteCutCommand()
    {
        var buffer = new List<byte> { 0x41, 0x1D, 0x56, 0x42 };

        var jobs = EscPosJobFramer.ExtractCutJobs(buffer);

        Assert.Empty(jobs);
        Assert.Equal(4, buffer.Count);
    }
}
