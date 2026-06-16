<?php
// The ordered list of provisioning steps every new agent goes through.
// is_auto=true means AgentEdge can provision this automatically via API.
// Manual steps require an admin to check them off.
function onboard_tools(): array {
    return [
        ['key'=>'agentedge',       'label'=>'AgentEdge Account',    'is_auto'=>false, 'note'=>'Created when added to queue'],
        ['key'=>'intranet',        'label'=>'Company Intranet',      'is_auto'=>false, 'note'=>'Add user in everythinginnovate.com'],
        ['key'=>'fub',             'label'=>'Follow Up Boss',        'is_auto'=>true,  'note'=>'Auto-provision via API'],
        ['key'=>'constellation1',  'label'=>'Constellation1',        'is_auto'=>true,  'note'=>'Auto-provision via API'],
        ['key'=>'dotloop',         'label'=>'DotLoop',               'is_auto'=>false, 'note'=>'Add manually in DotLoop admin'],
        ['key'=>'listingstoleads', 'label'=>'ListingsToLeads',       'is_auto'=>false, 'note'=>'Add manually'],
        ['key'=>'maxa',            'label'=>'MAXA Presents',         'is_auto'=>false, 'note'=>'Add manually'],
        ['key'=>'mls',             'label'=>'MLS Access',            'is_auto'=>false, 'note'=>'Submit MLS new member form'],
        ['key'=>'email_setup',     'label'=>'Email & Signature',     'is_auto'=>false, 'note'=>'Set up company email + signature'],
        ['key'=>'training',        'label'=>'New Agent Training',    'is_auto'=>false, 'note'=>'Enroll in onboarding training program'],
    ];
}
