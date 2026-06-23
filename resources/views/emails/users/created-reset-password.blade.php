<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your MIGECO DMS account login details</title>
</head>
<body style="margin:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7fb;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="background:#2563eb;padding:24px 28px;">
                            <div style="font-size:20px;font-weight:700;color:#ffffff;letter-spacing:0.04em;">
                                MIGECO DMS
                            </div>
                            <div style="margin-top:6px;font-size:13px;color:#dbeafe;">
                                Secure Document Management System
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:30px 28px;">
                            <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;color:#0f172a;">
                                Your account has been created
                            </h1>

                            <p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#475569;">
                                Hello {{ $user->name }},
                            </p>

                            <p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#475569;">
                                An administrator has created your MIGECO DMS account. Use the credentials below to sign in locally.
                            </p>

                            @if ($createdBy)
                                <p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#475569;">
                                    Created by:
                                    <strong style="color:#0f172a;">{{ $createdBy->name }}</strong>
                                </p>
                            @endif

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:22px 0;border:1px solid #dbeafe;border-radius:12px;overflow:hidden;">
                                <tr>
                                    <td style="width:150px;background:#eff6ff;padding:13px 16px;font-size:13px;font-weight:700;color:#1e3a8a;">
                                        Login URL
                                    </td>
                                    <td style="padding:13px 16px;font-size:13px;color:#0f172a;word-break:break-all;">
                                        <a href="{{ $loginUrl }}" style="color:#2563eb;text-decoration:none;">{{ $loginUrl }}</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="width:150px;background:#eff6ff;padding:13px 16px;font-size:13px;font-weight:700;color:#1e3a8a;border-top:1px solid #dbeafe;">
                                        Email
                                    </td>
                                    <td style="padding:13px 16px;font-size:13px;color:#0f172a;border-top:1px solid #dbeafe;">
                                        {{ $user->email }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="width:150px;background:#eff6ff;padding:13px 16px;font-size:13px;font-weight:700;color:#1e3a8a;border-top:1px solid #dbeafe;">
                                        Password
                                    </td>
                                    <td style="padding:13px 16px;font-size:15px;font-weight:700;color:#0f172a;border-top:1px solid #dbeafe;letter-spacing:0.04em;">
                                        {{ $temporaryPassword }}
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 24px;">
                                <tr>
                                    <td style="border-radius:10px;background:#2563eb;">
                                        <a href="{{ $loginUrl }}" style="display:inline-block;padding:13px 20px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
                                            Open Login Page
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0;padding:12px 14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;font-size:12px;line-height:1.6;color:#9a3412;">
                                Local testing note: this email includes a temporary password for convenience. For production, use a password reset link instead of sending passwords by email.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#64748b;">
                                This is an automated message from MIGECO DMS. If you did not expect this account, please contact your system administrator.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>