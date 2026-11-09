<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 32px 40px; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #172033; font-size: 9px; }
        .brand { display: table; width: 100%; border-bottom: 1px solid #E5E7EB; padding-bottom: 10px; margin-bottom: 14px; }
        .brand .left, .brand .right { display: table-cell; vertical-align: bottom; }
        .brand .right { text-align: right; color: #697386; }
        .mark { display: inline-block; width: 10px; height: 10px; border: 1.6px solid #536DFE; border-radius: 50%; margin-right: 6px; vertical-align: -1px; }
        .wordmark { font-size: 9px; letter-spacing: 2px; font-weight: bold; color: #172033; }
        h1 { font-size: 15px; margin: 6px 0 2px; font-weight: bold; }
        .label { font-size: 7.5px; letter-spacing: .8px; text-transform: uppercase; color: #697386; }
        .meta { color: #697386; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-weight: bold; font-size: 8px; color: #697386; background: #F1F3F6; padding: 5px 6px; border-bottom: 1px solid #D2D6DE; }
        td { padding: 4px 6px; border-bottom: 1px solid #E5E7EB; }
        th.num, td.num { text-align: right; }
        tr:nth-child(even) td { background: #FAFBFC; }
        .note { margin-top: 10px; color: #697386; font-size: 8px; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; color: #8A93A3; font-size: 7.5px; }
    </style>
</head>
<body>
    <div class="brand">
        <div class="left">
            <div><span class="mark"></span><span class="wordmark">ORBIT</span></div>
            <div class="label" style="margin-top: 8px">{{ $organization }}</div>
            <h1>{{ $title }}</h1>
        </div>
        <div class="right">
            <div class="label">{{ $reportType }}</div>
            <div>{{ $period }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th @class(['num' => $column->isNumeric()])>{{ $column->label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $i => $value)
                        <td @class(['num' => $columns[$i]->isNumeric()])>{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}">No data for this period and these filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($note)
        <p class="note">{{ $note }}</p>
    @endif

    <div class="footer">Generated {{ $generatedAt }} · {{ count($rows) }} rows · {{ $organization }} · ORBIT</div>
</body>
</html>
