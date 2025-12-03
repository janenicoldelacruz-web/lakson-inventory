<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $reportTitle ?? 'Inventory Report' }}</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; }
        .header-table { width: 100%; }
        .header-left { text-align: left; }
        .header-right { text-align: right; font-size: 10px; }

        .report-title { margin-top: 10px; text-align: center; font-size: 14px; font-weight: bold; }
        .subtitle     { text-align: center; font-size: 11px; margin-top: 2px; }

        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 5px; }
        th { background-color: #f2f2f2; font-size: 11px; }
        td { font-size: 10px; }
        .text-right  { text-align: right; }
        .text-center { text-align: center; }

        .signatory-section { margin-top: 40px; width: 100%; }
        .signatory-table   { width: 100%; }
        .signatory-cell    { width: 33%; text-align: center; font-size: 11px; }
        .sign-line {
            margin-top: 40px;
            border-top: 1px solid #000;
            width: 80%;
            margin-left: auto;
            margin-right: auto;
            padding-top: 2px;
        }
        .sign-name  { font-weight: bold; }
        .sign-title { font-size: 10px; }
    </style>
</head>
<body>

    <!-- HEADER -->
    <table class="header-table">
        <tr>
            <td class="header-left">
                <strong>{{ $company['name'] ?? 'Lakson Feed Trading' }}</strong><br>
                {{ $company['address'] ?? 'San Guillermo, Isabela' }}<br>
                {{ $company['contact'] ?? '09108655383' }}
            </td>
        </tr>
    </table>

    <!-- TITLE -->
    <div class="report-title">
        {{ $reportTitle ?? 'Inventory Report' }}
    </div>
    <div class="subtitle">
        As of {{ now()->format('F d, Y') }}
    </div>

    <!-- TABLE -->
    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Brand</th>
                <th class="text-right">Sacks</th>
                <th class="text-right">Pieces</th>
                <th class="text-right">Cost</th>
                <th class="text-right">Selling</th>
                <th class="text-right">Total Value</th>
            </tr>
        </thead>
        <tbody>
            @php $grandValue = 0; @endphp

            @foreach ($products as $p)
@php
    $sacks      = (float) ($p->current_stock ?? 0); // from DB
    $pcs        = 0;
    $qty        = $sacks + $pcs;
    $cost       = (float) ($p->cost_price ?? 0);
    $sell       = (float) ($p->selling_price ?? $p->price ?? 0);
    $totalValue = $qty * $cost;
    $grandValue += $totalValue;
@endphp
<tr>
    <td>{{ $p->name }}</td>
    <td>{{ optional($p->category)->name }}</td>
    <td>{{ optional($p->brand)->name }}</td>
    <td class="text-right">{{ number_format($sacks, 2) }}</td>
    <td class="text-right">{{ number_format($pcs, 2) }}</td>
    <td class="text-right">{{ number_format($cost, 2) }}</td>
    <td class="text-right">{{ number_format($sell, 2) }}</td>
    <td class="text-right">{{ number_format($totalValue, 2) }}</td>
</tr>

            @endforeach

            <tr>
                <td colspan="7" class="text-right"><strong>Total Inventory Value</strong></td>
                <td class="text-right"><strong>{{ number_format($grandValue, 2) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <!-- SIGNATORIES -->
    <div class="signatory-section">
        <table class="signatory-table">
            <tr>
                <td class="signatory-cell">
                    <div class="sign-line"></div>
                    <div class="sign-name">{{ $preparedBy ?? '________________________' }}</div>
                    <div class="sign-title">{{ $signatories['prepared_by_label'] ?? 'Prepared by' }}</div>
                </td>
                <td class="signatory-cell">
                    <div class="sign-line"></div>
                    <div class="sign-name">{{ $signatories['checked_by_name'] ?? '________________________' }}</div>
                    <div class="sign-title">{{ $signatories['checked_by_title'] ?? 'Checked by' }}</div>
                </td>
                <td class="signatory-cell">
                    <div class="sign-line"></div>
                    <div class="sign-name">{{ $signatories['approved_by_name'] ?? '________________________' }}</div>
                    <div class="sign-title">{{ $signatories['approved_by_title'] ?? 'Approved by' }}</div>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
