# POS Printer Emulator website

This directory contains the static production website for `posprinteremulator.com`.

## Contents

- `index.html`: public product, feature, licensing, download, and FAQ page.
- `privacy.html`: privacy information.
- `styles.css` and `script.js`: responsive presentation and interactions.
- `assets`: optimized website branding and the current application screenshot.
- `downloads`: the current Windows installer. Installer executables are excluded from Git because release binaries are generated artifacts.

## Preview locally

From the project root, use the existing Vite installation:

```console
node src\ReceiptEmulator.Viewer\node_modules\vite\bin\vite.js website --host 127.0.0.1 --port 4173
```

Open `http://127.0.0.1:4173`.

## Publish

Upload the contents of this directory—not the `website` directory itself—to the web root for `posprinteremulator.com` using SFTP on port 22. Include the hidden `.htaccess` file and the generated installer under `downloads`.

The SFTP password and other hosting credentials must never be committed to this repository.

The C# publisher in `tools/POSPrinterEmulator.WebsitePublisher` performs a non-destructive recursive upload and verifies each remote file size. It reads credentials only from temporary environment variables and validates the server's SHA-256 host-key fingerprint.
