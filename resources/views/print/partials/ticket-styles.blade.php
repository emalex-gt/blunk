<style>
    @page {
        size: 80mm auto;
        margin: 4mm;
    }

    * { box-sizing: border-box; }

    html,
    body {
        margin: 0;
        padding: 0;
    }

    body {
        background: #e5e7eb;
        color: #000;
        font: 10px Arial, Helvetica, sans-serif;
    }

    .ticket,
    .ticket-page {
        width: 72mm;
        max-width: 100%;
        margin: 0 auto 16px;
        padding: 0;
        background: #fff;
    }

    .toolbar {
        display: flex;
        justify-content: center;
        gap: 6px;
        margin: 10px auto;
        width: 72mm;
        max-width: 100%;
    }

    button {
        border: 1px solid #000;
        border-radius: 4px;
        background: #fff;
        color: #000;
        cursor: pointer;
        font: inherit;
        font-weight: 700;
        padding: 6px 10px;
    }

    h1,
    h2,
    p { margin: 0; }

    h1,
    h2,
    .title { font-size: 13px; }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: inherit;
    }

    th,
    td {
        padding: 5px 2px;
        vertical-align: top;
    }

    th {
        border-bottom: 1px solid #000;
        text-align: left;
    }

    .separator,
    .line {
        border-top: 1px dashed #000;
        margin: 6px 0;
    }

    .break,
    td,
    p,
    .item-name,
    .cancelled-info { overflow-wrap: anywhere; }

    .center { text-align: center; }
    .right,
    .money { text-align: right; }

    .row {
        display: flex;
        justify-content: space-between;
        gap: 6px;
    }

    .total {
        font-size: 13px;
        font-weight: 700;
    }

    .muted { font-size: 9px; }

    .item { margin: 5px 0; }
    .item-name { font-weight: 700; }

    .logo {
        display: block;
        max-width: 52mm;
        max-height: 24mm;
        margin: 0 auto 4px;
        object-fit: contain;
    }

    .note,
    .cancelled,
    .cancelled-badge {
        border: 1px dashed #000;
        font-weight: 700;
        margin: 6px 0;
        padding: 5px;
        text-align: center;
    }

    @media print {
        body { background: #fff; }

        .no-print,
        .toolbar { display: none !important; }

        .ticket,
        .ticket-page {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 0;
        }
    }
</style>
