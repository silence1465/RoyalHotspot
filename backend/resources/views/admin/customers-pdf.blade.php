<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: sans-serif; font-size: 11px; color: #1e293b; }
    h1 { font-size: 16px; margin-bottom: 2px; }
    p.meta { color: #64748b; font-size: 10px; margin-top: 0; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; background: #f1f5f9; padding: 6px 8px; font-size: 10px; border-bottom: 1px solid #cbd5e1; }
    td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
    tr:nth-child(even) { background: #f8fafc; }
</style>
</head>
<body>
    <h1>Customers</h1>
    <p class="meta">Exported {{ now()->toDayDateTimeString() }} — {{ count($customers) }} record(s)</p>

    <table>
        <thead>
            <tr>
                <th>Full Name</th>
                <th>Phone</th>
                <th>Username</th>
                <th>Email</th>
                <th>Status</th>
                <th>Registered</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($customers as $customer)
                <tr>
                    <td>{{ $customer->full_name }}</td>
                    <td>{{ $customer->phone }}</td>
                    <td>{{ $customer->username }}</td>
                    <td>{{ $customer->email ?? '—' }}</td>
                    <td>{{ ucfirst($customer->status) }}</td>
                    <td>{{ $customer->created_at->toDateString() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
