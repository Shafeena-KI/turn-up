<?php

namespace App\Libraries;

class InviteLibrary
{

    // Function to generate Invite Reference Code
    public function generateInviteCode($event_code = null, $new_invite_no = null)
    {
        $prefix = env('INVITE_CODE_PREFIX') ?? 'IN';

        return $prefix . $event_code . str_pad($new_invite_no, 3, '0', STR_PAD_LEFT);
    }
}