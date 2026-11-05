<!doctype html>
<html lang="en"><body style="font-family: Arial, sans-serif; color: #0F1B2D; line-height: 1.5">
<p>Dear {{ $ownerName }},</p>
<p>Your Quick School AI account for <strong>{{ $tenant->name }}</strong> is ready at <strong>{{ $tenant->code }}.{{ config('platform.base_domain') }}</strong>.</p>
<p>Set your password to sign in. The link works for 7 days:</p>
<p><a href="{{ $link }}" style="background:#2451D6;color:#ffffff;padding:10px 18px;border-radius:8px;text-decoration:none">Set my password</a></p>
<p style="color:#556377;font-size:13px">If you did not expect this email, you can ignore it.<br>Quick ERP Technologies Pvt. Ltd.</p>
</body></html>
