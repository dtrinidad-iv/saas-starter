<x-mail::message>
# You're invited to {{ $organization }}

{{ $inviter }} invited you to join **{{ $organization }}** with the **{{ $role }}** role.

<x-mail::button :url="$url">
Accept invitation
</x-mail::button>

This link expires on {{ $expiresAt }}. Log in or create an account with this email address to accept it.

If you weren't expecting this, you can ignore this email.
</x-mail::message>
