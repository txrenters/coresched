<?php

namespace App\Enums;

enum TeamPermission: string
{
    case UpdateTeam = 'team:update';
    case DeleteTeam = 'team:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    case ManageGroups = 'group:manage';
    case ManageEventTypes = 'event_type:manage';
    case ManageTeamBookings = 'booking:manage';
    case ManageAutomations = 'automation:manage';
    case ViewActivity = 'activity:view';

    case ManageApiKeys = 'api_key:manage';
}
