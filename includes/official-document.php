<?php
/**
 * Official printable document branding shared by certificates and report cards.
 */

if (!function_exists('officialDocumentAssetUrl')) {
    function officialDocumentAssetUrl(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        $filePath = __DIR__ . '/../' . $relativePath;
        $version = is_file($filePath) ? (string)filemtime($filePath) : (defined('APP_VERSION') ? APP_VERSION : '1');

        return APP_URL . '/' . $relativePath . '?v=' . rawurlencode($version);
    }
}

if (!function_exists('renderOfficialDocumentStyles')) {
    function renderOfficialDocumentStyles(): void
    {
        ?>
<style>
    .official-document-sheet {
        max-width: 8.27in;
        min-height: 11.2in;
        margin: 0 auto;
        padding: 0.32in 0.48in;
        background: #fff;
        color: #111827;
        border: 1px solid #d7dde7;
        border-radius: 8px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        font-family: "Times New Roman", Times, serif;
        display: flex;
        flex-direction: column;
    }

    .official-document-header {
        position: relative;
        min-height: 1.12in;
        padding: 0.02in 0.9in 0.16in;
        border-bottom: 2px solid #0070c0;
        text-align: center;
    }

    .official-document-logo {
        position: absolute;
        top: 0.02in;
        width: 0.78in;
        height: 0.78in;
        object-fit: contain;
    }

    .official-document-logo-left {
        left: 0;
    }

    .official-document-logo-right {
        right: 0;
    }

    .official-school-name {
        margin: 0 0 0.03in;
        color: #0070c0;
        font-family: "Arial Black", Arial, sans-serif;
        font-size: 20pt;
        line-height: 1.05;
        font-weight: 900;
    }

    .official-school-line {
        margin: 0;
        font-size: 8pt;
        line-height: 1.25;
    }

    .official-document-body {
        flex: 1;
        padding: 0.34in 0.08in 0.15in;
        font-size: 12pt;
        line-height: 1.65;
    }

    .official-document-title {
        margin: 0 0 0.28in;
        text-align: center;
        text-transform: uppercase;
        font-size: 20pt;
        line-height: 1.2;
        font-weight: 700;
        letter-spacing: 0.01em;
    }

    .official-salutation {
        margin-bottom: 0.22in;
        font-weight: 700;
    }

    .official-paragraph {
        margin-bottom: 0.18in;
        text-align: justify;
    }

    .official-fill {
        font-weight: 700;
        text-decoration: underline;
        text-underline-offset: 2px;
    }

    .official-signatures {
        display: flex;
        justify-content: space-between;
        gap: 0.5in;
        margin-top: 0.42in;
    }

    .official-signature {
        flex: 1;
        text-align: center;
    }

    .official-signature-caption {
        margin-bottom: 0.38in;
        text-align: left;
        font-size: 11pt;
    }

    .official-signature-name {
        display: inline-block;
        min-width: 2.6in;
        padding: 0 0.08in 0.02in;
        border-bottom: 1px solid #111827;
        font-weight: 700;
        text-transform: uppercase;
    }

    .official-signature-role {
        margin-top: 0.03in;
        font-weight: 700;
    }

    .official-document-footer {
        margin-top: auto;
        padding-top: 0.22in;
        text-align: center;
        color: #4472c4;
        font-family: "Monotype Corsiva", "Palatino Linotype", serif;
        font-size: 13pt;
        font-weight: 700;
    }

    @page {
        size: A4 portrait;
        margin: 0.5in;
    }

    @media print {
        body {
            background: #fff !important;
        }

        .no-print,
        .sidebar,
        .top-header,
        .main-footer,
        .sidebar-overlay {
            display: none !important;
        }

        .main-content {
            margin: 0 !important;
            padding: 0 !important;
            min-height: auto !important;
        }

        .official-document-sheet {
            width: 100%;
            max-width: none;
            min-height: auto;
            margin: 0;
            padding: 0;
            border: none;
            border-radius: 0;
            box-shadow: none;
        }

        .official-document-header {
            padding-top: 0;
        }
    }
</style>
        <?php
    }
}

if (!function_exists('renderOfficialDocumentHeader')) {
    function renderOfficialDocumentHeader(): void
    {
        ?>
<header class="official-document-header">
    <img class="official-document-logo official-document-logo-left"
         src="<?= e(officialDocumentAssetUrl('assets/images/branding/agape-document-left.jpeg')) ?>"
         alt="Agape Boracay Academy logo">
    <img class="official-document-logo official-document-logo-right"
         src="<?= e(officialDocumentAssetUrl('assets/images/branding/agape-document-right.png')) ?>"
         alt="Agape Boracay Academy seal">

    <h1 class="official-school-name">AGAPE BORACAY ACADEMY INC.</h1>
    <p class="official-school-line">Sitio Cagban, Brgy. Manocmanoc, Boracay Island, Malay, Aklan 5608</p>
    <p class="official-school-line">Government Recognition (R-VI) No. ER-006 S. 2018 ER-007 S. 2018 | School ID: 438045</p>
    <p class="official-school-line">BIR TIN: 410-996-536-000 | Tel. No.: 272-4230 / 288-2165 | Email: agapeschool05@gmail.com</p>
    <p class="official-school-line">SEC Company Reg. No.: CN20112</p>
</header>
        <?php
    }
}

if (!function_exists('renderOfficialDocumentFooter')) {
    function renderOfficialDocumentFooter(): void
    {
        ?>
<footer class="official-document-footer">
    "Your word is a lamp to my feet and a light to my path." Psalm 119:105
</footer>
        <?php
    }
}
