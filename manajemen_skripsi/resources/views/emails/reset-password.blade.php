<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Sistem Manajemen Skripsi</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #334155; -webkit-font-smoothing: antialiased; line-height: 1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f1f5f9; padding: 30px 15px;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 560px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;">
                    
                    <!-- Top Accent Line -->
                    <tr>
                        <td height="5" style="background-color: #15803d; font-size: 0; line-height: 0;">&nbsp;</td>
                    </tr>

                    <!-- Header -->
                    <tr>
                        <td align="center" style="padding: 35px 30px 20px 30px; text-align: center;">
                            @php
                                $logoPath = public_path('images/logo-unuha.webp');
                                $hasEmbed = isset($message) && is_object($message) && method_exists($message, 'embed');
                                $logoSrc = ($hasEmbed && file_exists($logoPath))
                                    ? $message->embed($logoPath)
                                    : rtrim(config('app.url'), '/') . '/images/logo-unuha.webp';
                            @endphp
                            <img src="{{ $logoSrc }}" alt="Logo UNUHA" width="75" height="75" style="display: block; margin: 0 auto 16px auto; border: 0; outline: none; object-fit: contain;">
                            
                            <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #0f172a; letter-spacing: -0.02em;">
                                Sistem Manajemen Skripsi
                            </h1>
                            <p style="margin: 4px 0 0 0; font-size: 13px; color: #64748b; font-weight: 500;">
                                Universitas Nurul Huda
                            </p>
                        </td>
                    </tr>

                    <!-- Divider -->
                    <tr>
                        <td style="padding: 0 30px;">
                            <hr style="border: 0; border-top: 1px solid #f1f5f9; margin: 0;">
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 30px 35px 25px 35px;">
                            <p style="margin: 0 0 16px 0; font-size: 15px; color: #1e293b; font-weight: 600;">
                                Halo, {{ $user->name ?? 'Pengguna' }},
                            </p>
                            <p style="margin: 0 0 24px 0; font-size: 14px; color: #475569; line-height: 1.65;">
                                Kami menerima permintaan untuk mengatur ulang (reset) password akun Anda pada <strong>Sistem Manajemen Skripsi</strong> Universitas Nurul Huda. Untuk membuat password baru, silakan tekan tombol di bawah ini:
                            </p>

                            <!-- Button Container -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0;">
                                <tr>
                                    <td align="center">
                                        <!--[if mso]>
                                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:44px;v-text-anchor:middle;width:220px;" arcsize="18%" stroke="f" fillcolor="#15803d">
                                        <w:anchorlock/>
                                        <center style="color:#ffffff;font-family:sans-serif;font-size:14px;font-weight:bold;">Reset Password</center>
                                        </v:roundrect>
                                        <![endif]-->
                                        <!--[if !mso]><!-- -->
                                        <a href="{{ $url }}" target="_blank" style="display: inline-block; background-color: #15803d; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 600; padding: 13px 32px; border-radius: 8px; box-shadow: 0 2px 4px rgba(21, 128, 61, 0.25); text-align: center; mso-padding-alt: 0;">
                                            Reset Password
                                        </a>
                                        <!--<![endif]-->
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 24px 0 10px 0; font-size: 13px; color: #64748b; line-height: 1.5;">
                                Tautan reset password ini berlaku selama <strong>60 menit</strong>. Jika Anda tidak merasa melakukan permintaan ini, Anda dapat mengabaikan email ini dengan aman dan password Anda tidak akan berubah.
                            </p>
                        </td>
                    </tr>

                    <!-- Alternative Link Section -->
                    <tr>
                        <td style="padding: 0 35px 30px 35px;">
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;">
                                <p style="margin: 0 0 6px 0; font-size: 12px; color: #64748b; font-weight: 500;">
                                    Jika Anda mengalami kendala saat menekan tombol di atas, salin dan tempel tautan berikut ke peramban Anda:
                                </p>
                                <p style="margin: 0; font-size: 11px; color: #15803d; word-break: break-all; line-height: 1.4;">
                                    <a href="{{ $url }}" target="_blank" style="color: #15803d; text-decoration: underline;">
                                        {{ $url }}
                                    </a>
                                </p>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px 30px; text-align: center; border-top: 1px solid #f1f5f9;">
                            <p style="margin: 0; font-size: 12px; color: #94a3b8;">
                                &copy; {{ date('Y') }} Universitas Nurul Huda. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>