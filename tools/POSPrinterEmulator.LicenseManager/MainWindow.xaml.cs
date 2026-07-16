using Microsoft.Win32;
using POSPrinterEmulator.Licensing;
using System.IO;
using System.Windows;
using System.Windows.Controls;

namespace POSPrinterEmulator.LicenseManager;

public partial class MainWindow : Window
{
    public MainWindow()
    {
        InitializeComponent();
        PrivateKeyPathTextBox.Text = FindDefaultPrivateKey() ?? string.Empty;
        CustomerNameTextBox.Focus();
    }

    private void BrowsePrivateKey_Click(object sender, RoutedEventArgs e)
    {
        var dialog = new OpenFileDialog
        {
            Title = "Select the POS Printer Emulator private signing key",
            Filter = "PEM private key (*.pem)|*.pem|All files (*.*)|*.*",
            CheckFileExists = true,
            Multiselect = false
        };

        var currentPath = PrivateKeyPathTextBox.Text.Trim();
        if (File.Exists(currentPath))
        {
            dialog.InitialDirectory = Path.GetDirectoryName(currentPath);
            dialog.FileName = Path.GetFileName(currentPath);
        }

        if (dialog.ShowDialog(this) == true)
        {
            PrivateKeyPathTextBox.Text = dialog.FileName;
            HideMessages();
        }
    }

    private void Generate_Click(object sender, RoutedEventArgs e)
    {
        HideMessages();
        try
        {
            var privateKeyPath = PrivateKeyPathTextBox.Text.Trim();
            if (!File.Exists(privateKeyPath))
            {
                throw new InvalidOperationException("Select your vendor-private-key.pem file.");
            }

            var customerName = CustomerNameTextBox.Text.Trim();
            var emailAddress = EmailTextBox.Text.Trim();
            var tierName = (LicenseTierComboBox.SelectedItem as ComboBoxItem)?.Tag?.ToString() ?? nameof(LicenseTier.Pro);
            var tier = Enum.Parse<LicenseTier>(tierName);
            var activationKey = ActivationKeyCodec.Issue(
                File.ReadAllText(privateKeyPath),
                customerName,
                emailAddress,
                tier);

            ActivationKeyTextBox.Text = activationKey;
            IssuedToTextBlock.Text = $"{tier} License issued to {customerName} · {emailAddress.ToLowerInvariant()}";
            ResultPanel.Visibility = Visibility.Visible;
            ActivationKeyTextBox.Focus();
            ActivationKeyTextBox.SelectAll();
        }
        catch (Exception exception)
        {
            ErrorTextBlock.Text = exception.Message;
            ErrorPanel.Visibility = Visibility.Visible;
        }
    }

    private void Copy_Click(object sender, RoutedEventArgs e)
    {
        if (!string.IsNullOrWhiteSpace(ActivationKeyTextBox.Text))
        {
            Clipboard.SetText(ActivationKeyTextBox.Text);
            MessageBox.Show(this, "The activation key was copied to the clipboard.", "License Manager",
                MessageBoxButton.OK, MessageBoxImage.Information);
        }
    }

    private void CreateAnother_Click(object sender, RoutedEventArgs e)
    {
        CustomerNameTextBox.Clear();
        EmailTextBox.Clear();
        ActivationKeyTextBox.Clear();
        HideMessages();
        CustomerNameTextBox.Focus();
    }

    private void HideMessages()
    {
        ResultPanel.Visibility = Visibility.Collapsed;
        ErrorPanel.Visibility = Visibility.Collapsed;
    }

    private static string? FindDefaultPrivateKey()
    {
        var candidates = new[]
        {
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments),
                "Web Base EPSON Emulator", "License Keys", "vendor-private-key.pem"),
            Path.GetFullPath(Path.Combine(AppContext.BaseDirectory,
                "..", "..", "..", "..", "License Keys", "vendor-private-key.pem"))
        };

        return candidates.FirstOrDefault(File.Exists);
    }
}
