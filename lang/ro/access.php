<?php

return [
    'denied' => 'Nu aveți dreptul să efectuați această acțiune.',
    'escalation' => 'Nu puteți acorda drepturi pe care nu le aveți: :codes.',
    'scopes' => [
        'organization' => 'Toată organizația',
        'org_unit' => 'Subdiviziune (cu subordonatele)',
        'territory' => 'Teritoriu (cu subteritoriile)',
        'own_unit' => 'Subdiviziunea proprie',
        'own_territories' => 'Teritoriile proprii',
    ],
    'errors' => [
        'reason_required' => 'Indicați motivul.',
        'end_date_in_past' => 'Data de încheiere este în trecut.',
        'scope_invalid' => 'Aria de acțiune este indicată incorect.',
        'territory_outside_own_access' => 'Puteți acorda doar teritorii din propriul acces.',
    ],
];
