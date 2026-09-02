<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Your Royal WiFi Voucher</title>
</head>
<body style="font-family: -apple-system, Arial, sans-serif; background:#f8fafc; margin:0; padding:24px;">
    <table role="presentation" width="100%" style="max-width:480px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e2e8f0;">
        <tr>
            <td style="background:#4f46e5; padding:24px; text-align:center;">
                <h1 style="color:#ffffff; margin:0; font-size:20px;">Royal WiFi</h1>
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <p style="font-size:16px; color:#0f172a; margin-top:0;">Payment successful — you're all set!</p>

                <table role="presentation" width="100%" style="background:#f1f5f9; border-radius:8px; padding:16px; margin:16px 0;">
                    <tr>
                        <td style="font-size:13px; color:#64748b; padding-bottom:4px;">Voucher Code</td>
                    </tr>
                    <tr>
                        <td style="font-size:22px; font-weight:700; font-family:monospace; color:#0f172a; letter-spacing:1px;">
                            {{ $voucher->code ?? 'Contact support' }}
                        </td>
                    </tr>
                </table>

                <table role="presentation" width="100%" style="font-size:14px; color:#334155;">
                    <tr>
                        <td style="padding:4px 0;">Package</td>
                        <td style="padding:4px 0; text-align:right; font-weight:600;">{{ $package->name }}</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Amount</td>
                        <td style="padding:4px 0; text-align:right; font-weight:600;">GH₵{{ number_format($order->amount, 2) }}</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Duration</td>
                        <td style="padding:4px 0; text-align:right; font-weight:600;">{{ $voucher->duration_days ?? $package->duration_value }} days</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Purchase Date</td>
                        <td style="padding:4px 0; text-align:right; font-weight:600;">{{ $order->verified_at?->format('d M Y, g:i A') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Reference</td>
                        <td style="padding:4px 0; text-align:right; font-weight:600;">{{ $order->reference }}</td>
                    </tr>
                </table>

                <p style="font-size:13px; color:#94a3b8; margin-top:24px;">
                    Enter this code on the Royal WiFi hotspot login page to connect. Keep it safe — anyone with the code can use it.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
