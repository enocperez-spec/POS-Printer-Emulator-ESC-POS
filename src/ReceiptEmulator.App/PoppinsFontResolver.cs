using System.Reflection;
using PdfSharp.Fonts;

namespace ReceiptEmulator;

internal sealed class PoppinsFontResolver : IFontResolver
{
    private const string RegularFace = "PPE-Poppins-Regular";
    private const string SemiBoldFace = "PPE-Poppins-SemiBold";
    private static readonly Lazy<byte[]> Regular = new(() => ReadResource("ReceiptEmulator.PoppinsRegular"));
    private static readonly Lazy<byte[]> SemiBold = new(() => ReadResource("ReceiptEmulator.PoppinsSemiBold"));

    public FontResolverInfo? ResolveTypeface(string familyName, bool isBold, bool isItalic) =>
        familyName.Equals("Poppins", StringComparison.OrdinalIgnoreCase)
            ? new FontResolverInfo(isBold ? SemiBoldFace : RegularFace)
            : null;

    public byte[]? GetFont(string faceName) => faceName switch
    {
        RegularFace => Regular.Value,
        SemiBoldFace => SemiBold.Value,
        _ => null
    };

    private static byte[] ReadResource(string name)
    {
        using var stream = Assembly.GetExecutingAssembly().GetManifestResourceStream(name)
            ?? throw new InvalidOperationException($"Embedded font resource {name} was not found.");
        using var memory = new MemoryStream();
        stream.CopyTo(memory);
        return memory.ToArray();
    }
}
