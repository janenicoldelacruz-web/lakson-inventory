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

        .report-title {
            margin-top: 10px;
            text-align: center;
            font-size: 14px;
            font-weight: bold;
        }
        .subtitle {
            text-align: center;
            font-size: 11px;
            margin-top: 2px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 5px;
        }
        th {
            background-color: #f2f2f2;
            font-size: 11px;
        }
        td {
            font-size: 10px;
        }

        .text-right  { text-align: right; }
        .text-center { text-align: center; }

        /* Category header row */
        .category-row td {
            background-color: #e6e6e6;
            font-weight: bold;
            font-size: 11px;
        }

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
            <td class="header-right">
                Date: {{ now()->format('F d, Y') }}<br>
                Time: {{ now()->format('h:i A') }}
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
            @php
                $grandValue = 0;
                $currentCategoryId = null;
                $SACK_WEIGHT_KG = 50; // 50kg per sack
            @endphp

            @foreach ($products as $p)
                @php
                    $category = $p->category;
                    $categoryId = $category->id ?? null;
                    $categoryName = $category->name ?? 'Uncategorized';
                @endphp

                {{-- CATEGORY HEADER ROW WHEN CATEGORY CHANGES --}}
                @if ($categoryId !== $currentCategoryId)
                    @php $currentCategoryId = $categoryId; @endphp
                    <tr class="category-row">
                        <td colspan="8">
                            {{ strtoupper($categoryName) }}
                        </td>
                    </tr>
                @endif

                @php
                    // --- STOCK & VALUE CALCULATION ---
                    $currentStock = (float) ($p->current_stock ?? 0);
                    $baseUnit     = (int) ($p->base_unit ?? 1); // 1 = Sack (feed), 2 = Piece

                    $sacksDecimal = 0.0;
                    $pieces       = 0;
                    $qtyForValue  = 0.0;

                    if ($baseUnit === 1) {
                        // FEED: KG -> decimal sacks (e.g. 530kg = 10.6 sacks)
                        $sacksDecimal = $SACK_WEIGHT_KG > 0 ? ($currentStock / $SACK_WEIGHT_KG) : 0;
                        $pieces       = 0;
                        $qtyForValue  = $sacksDecimal; // value per sack
                    } else {
                        // NON-FEED: pieces
                        $sacksDecimal = 0.0;
                        $pieces       = (int) $currentStock;
                        $qtyForValue  = $pieces; // value per piece
                    }

                    $cost = (float) ($p->cost_price ?? 0);                 // per sack or piece
                    $sell = (float) ($p->selling_price ?? $p->price ?? 0); // per sack or piece

                    $totalValue  = $qtyForValue * $cost;
                    $grandValue += $totalValue;
                @endphp

                <tr>
                    <td>{{ $p->name }}</td>
                    <td>{{ $categoryName }}</td>
                    <td>{{ optional($p->brand)->name }}</td>

                    {{-- Sacks column: show decimal with 1 decimal place (e.g. 10.6) --}}
                    <td class="text-right">
                        {{ $sacksDecimal > 0 ? number_format($sacksDecimal, 1) : '0.0' }}
                    </td>

                    {{-- Pieces: whole numbers --}}
                    <td class="text-right">{{ number_format($pieces, 0) }}</td>

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
