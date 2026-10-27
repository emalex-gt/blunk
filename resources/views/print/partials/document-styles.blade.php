<style>
    * { box-sizing: border-box; }

    body {
        margin: 0;
        background: #eef2f7;
        color: #111827;
        font: 12px Arial, Helvetica, sans-serif;
    }

    .toolbar {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        max-width: 900px;
        margin: 16px auto 10px;
    }

    button {
        border: 1px solid #0d62ab;
        border-radius: 4px;
        background: #fff;
        color: #0d62ab;
        cursor: pointer;
        font: inherit;
        font-weight: 700;
        padding: 8px 13px;
    }

    button.primary {
        background: #0d62ab;
        color: #fff;
    }

    .document-page {
        max-width: 900px;
        min-height: 10in;
        margin: 0 auto 24px;
        padding: 24px;
        background: #fff;
    }

    .document-header {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(190px, 250px);
        gap: 20px;
        align-items: start;
        padding-bottom: 14px;
        border-bottom: 2px solid #0d62ab;
    }

    .document-brand {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        min-width: 0;
    }

    .document-logo,
    .company-logo,
    .logo {
        display: block;
        max-width: 165px;
        max-height: 80px;
        object-fit: contain;
        object-position: left top;
    }

    .document-company h1,
    .document-title h1,
    .document-title h2,
    h1,
    h2,
    p { margin: 0; }

    .document-company h1 {
        font-size: 18px;
        color: #0d62ab;
    }

    .document-title {
        text-align: right;
    }

    .document-title h1,
    .document-title h2 {
        color: #0d62ab;
        font-size: 20px;
        text-transform: uppercase;
    }

    .document-meta,
    .muted,
    .small { font-size: 10px; }

    .document-info,
    .document-box {
        margin-top: 16px;
        padding: 12px;
        border: 1px solid #0d62ab;
        border-radius: 5px;
    }

    .document-info {
        display: grid;
        grid-template-columns: minmax(0, 2fr) minmax(150px, 1fr);
        gap: 16px;
    }

    .label {
        color: #0d62ab;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
    }

    table {
        width: 100%;
        margin-top: 16px;
        border-collapse: collapse;
    }

    thead { display: table-header-group; }

    th {
        padding: 7px 6px;
        background: #0d62ab;
        color: #fff;
        font-size: 10px;
        text-align: left;
        text-transform: uppercase;
    }

    td {
        padding: 7px 6px;
        vertical-align: top;
        border-bottom: 1px solid #d7e2ec;
        overflow-wrap: anywhere;
    }

    tr {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .right { text-align: right; }
    .center { text-align: center; }

    .document-summary {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(250px, 320px);
        gap: 18px;
        margin-top: 16px;
    }

    .document-summary table { margin-top: 0; }

    .total {
        color: #0d62ab;
        font-size: 18px;
        font-weight: 700;
    }

    .document-note,
    .document-warning,
    .cancelled-badge,
    .cancelled {
        margin-top: 16px;
        padding: 10px 12px;
        border: 2px solid #0d62ab;
        font-weight: 700;
    }

    .document-note { border-width: 1px; }

    .document-warning,
    .cancelled-badge,
    .cancelled {
        border-color: #000;
        color: #000;
        font-size: 16px;
        text-align: center;
    }

    .cancelled-info { margin-top: 10px; }

    .footer {
        margin-top: 20px;
        padding-top: 12px;
        border-top: 1px solid #0d62ab;
        font-weight: 700;
        text-align: center;
    }

    @media print {
        body { background: #fff; }

        .no-print { display: none !important; }

        th {
            background: #fff !important;
            color: #000 !important;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }

        .label,
        .total,
        .document-company h1,
        .document-title h1,
        .document-title h2 {
            color: #000;
        }

        .document-header,
        .document-info,
        .document-box,
        .document-note,
        .document-warning,
        .certifier {
            border-color: #000;
        }

        .document-page {
            max-width: none;
            min-height: 0;
            margin: 0;
            padding: 0;
        }
    }
</style>
