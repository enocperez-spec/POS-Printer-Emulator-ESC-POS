# POS Printer Emulator website

This directory contains the static production website for `posprinteremulator.com`.

## Contents

- `index.html`: public product, feature, licensing, download, and FAQ page.
- `application-maintenance-support.html`: canonical permanent-license and optional annual maintenance policy, renewal pricing, and renewal links.
- `documentation.html`: product documentation and links to task-specific setup guides.
- `how-to-back-up-and-restore-pos-printer-emulator.html`: illustrated backup and restore instructions, including legacy file-name guidance.
- `privacy.html`: privacy information.
- `eula.html`: public End User License Agreement shown during installation.
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

After a successful publish, the tool reads `sitemap.xml` and submits every public URL to IndexNow. The public verification key is stored in `indexnow-key.txt` and is uploaded with the site.

## Search-engine ownership verification

Google Search Console and Bing Webmaster Tools issue a domain-specific verification token after the site is added to the owner's account. Keep those account tokens out of Git and provide them only as temporary environment variables when publishing:

```powershell
$env:PPE_GOOGLE_SITE_VERIFICATION = 'google-token-without-the-html-extension'
$env:PPE_BING_SITE_AUTH_TOKEN = 'bing-verification-token'
dotnet run --project tools\POSPrinterEmulator.WebsitePublisher --configuration Release --no-build -- publish website .
```

The publisher creates the required Google HTML verification file and `BingSiteAuth.xml` directly on the server. It does not write either token into the project directory. After publishing, complete verification in each webmaster portal and submit `https://www.posprinteremulator.com/sitemap.xml`.
