<?php

return [
    'title' => 'Jurnalul evenimentelor',
    'categories' => [
        'security' => 'Securitate',
        'access' => 'Acces',
        'data' => 'Modificarea datelor',
        'business' => 'Eveniment de activitate',
        'admin' => 'Administrare',
        'integration' => 'Integrări',
    ],
    'severities' => [
        'info' => 'Informație',
        'notice' => 'Important',
        'warning' => 'Avertisment',
        'critical' => 'Critic',
    ],
    'actor_types' => [
        'user' => 'Utilizator',
        'guest' => 'Vizitator',
        'system' => 'Sistem',
        'job' => 'Sarcină de fundal',
        'automation' => 'Automatizare',
        'ai' => 'IA',
    ],
    'acting_as' => [
        'own' => 'Drepturi proprii',
        'delegation' => 'Prin delegare',
        'impersonation' => 'Impersonare',
    ],
    'events' => [
        'audit' => [
            'viewed' => 'Vizualizarea jurnalului evenimentelor',
            'exported' => 'Exportul jurnalului evenimentelor',
            'settings' => [
                'updated' => 'Setările jurnalului au fost modificate',
            ],
            'retention' => [
                'purged' => 'Au fost șterse înregistrările cu termen de păstrare expirat',
            ],
        ],
        'access' => [
            'role' => [
                'created' => 'Rol creat',
                'updated' => 'Rol modificat',
                'permissions_changed' => 'Drepturile rolului au fost modificate',
                'assigned' => 'Rol atribuit',
                'revoked' => 'Rol retras',
                'delegated' => 'Rol delegat temporar',
                'expired' => 'Rol retras automat la expirare',
                'field_rules_changed' => 'Drepturile rolului asupra grupurilor de câmpuri modificate',
            ],
            'reserved_permission' => [
                'granted' => 'A fost acordat un drept rezervat',
            ],
            'escalation' => [
                'denied' => 'Refuz: încercare de a acorda drepturi mai largi decât cele proprii',
            ],
            'system_roles' => [
                'synced' => 'Rolurile de sistem au fost sincronizate',
            ],
            'territory' => [
                'expired' => 'Teritoriu retras automat la expirare',
                'grant_denied' => 'Refuz: acordarea unui teritoriu',
                'granted' => 'Teritoriu suplimentar acordat',
                'revoked' => 'Teritoriu suplimentar retras',
            ],
            'simulated' => 'Simulatorul drepturilor deschis pentru un utilizator',
        ],
        'catalogs' => [
            'item' => [
                'created' => 'Element creat',
                'updated' => 'Element modificat',
                'deactivated' => 'Element dezactivat',
                'reactivated' => 'Element reactivat',
                'merged' => 'Elemente unificate',
            ],
            'proposal' => [
                'submitted' => 'Propunere depusă',
                'approved' => 'Propunere aprobată',
                'rejected' => 'Propunere respinsă',
            ],
            'reference_data' => [
                'imported' => 'Date de referință încărcate',
            ],
        ],
        'identity' => [
            'registration' => [
                'submitted' => 'Cerere de înregistrare depusă',
            ],
            'email' => [
                'verified' => 'Adresă de e-mail confirmată',
            ],
            'profile' => [
                'updated' => 'Profil propriu modificat',
            ],
            'password' => [
                'reset_completed' => 'Parola a fost restabilită prin link',
                'changed' => 'Parolă schimbată',
                'reset_forced' => 'Resetare forțată a parolei',
            ],
            'provider' => [
                'linked' => 'Metodă de autentificare conectată',
                'unlinked' => 'Metodă de autentificare deconectată',
            ],
            'user' => [
                'deactivated' => 'Utilizator dezactivat',
                'reactivated' => 'Utilizator reactivat',
            ],
            'two_factor' => [
                'enabled' => 'Autentificarea în doi pași a fost activată',
                'disabled' => 'Autentificarea în doi pași a fost dezactivată',
                'reset' => 'Autentificarea în doi pași a fost resetată',
            ],
            'accounts' => [
                'linked' => 'Conturi legate de aceeași fișă de persoană',
            ],
            'auth_provider' => [
                'saved' => 'Setările furnizorului de autentificare au fost modificate',
            ],
            'impersonation' => [
                'started' => 'Început impersonare',
                'ended' => 'Sfârșit impersonare',
            ],
        ],
        'auth' => [
            'login' => [
                'succeeded' => 'Autentificare reușită',
                'failed' => 'Încercare de autentificare eșuată',
                'failed_series' => 'Serie de încercări de autentificare eșuate',
                'new_device' => 'Autentificare de pe un dispozitiv nou',
            ],
            'demo_login' => 'Autentificare demo (sub un personaj)',
            'logout' => 'Deconectare',
            'sessions' => [
                'terminated' => 'Toate sesiunile utilizatorului au fost închise',
            ],
        ],
        'admission' => [
            'invitation' => [
                'sent' => 'Invitație trimisă',
                'accepted' => 'Invitație acceptată',
                'revoked' => 'Invitație revocată',
            ],
            'application' => [
                'approved' => 'Cerere aprobată',
                'rejected' => 'Cerere respinsă',
            ],
        ],
        'people' => [
            'candidate' => [
                'registered' => 'Candidat înregistrat',
            ],
            'link_hint' => [
                'created' => 'Indiciu: persoana ar putea avea două conturi',
                'dismissed' => 'Indiciul despre două conturi a fost respins',
            ],
            'exported' => 'Export de persoane',
            'person' => [
                'created' => 'Fișă de persoană creată',
                'updated' => 'Fișă de persoană modificată',
                'archived' => 'Fișă de persoană trimisă în arhivă',
                'restored' => 'Fișă de persoană restabilită',
            ],
        ],
        'geo' => [
            'street' => [
                'renamed' => 'Denumirea străzii a fost corectată',
                'merged' => 'Străzi comasate în nomenclatorul adreselor',
            ],
            'house' => [
                'created' => 'Casă adăugată',
                'updated' => 'Casă modificată',
                'apartments_added' => 'Apartamente adăugate la casă',
                'apartment_removed' => 'Apartament șters din casă',
                'archived' => 'Casă trimisă în arhivă',
                'restored' => 'Casă scoasă din arhivă',
            ],
            'assignment' => [
                'created' => 'Agitator atribuit unei case sau unui teritoriu',
                'ended' => 'Atribuirea agitatorului a fost retrasă',
            ],
            'visit' => [
                'recorded' => 'Vizită la apartament înregistrată',
            ],
            'zone' => [
                'created' => 'Geozonă creată',
                'updated' => 'Geozonă modificată',
                'archived' => 'Geozonă trimisă în arhivă',
                'restored' => 'Geozonă scoasă din arhivă',
            ],
            'location' => [
                'sharing_started' => 'Participantul a pornit partajarea locației',
                'sharing_stopped' => 'Participantul a oprit partajarea locației',
                'track_viewed' => 'Traseul participantului a fost vizualizat',
                'purged' => 'Punctele de locație vechi au fost șterse',
            ],
            'vehicle' => [
                'created' => 'Vehicul adăugat',
                'updated' => 'Vehicul modificat',
                'archived' => 'Vehicul trimis în arhivă',
                'restored' => 'Vehicul scos din arhivă',
                'tracker_key_issued' => 'Cheia trackerului a fost emisă',
                'tracker_key_revoked' => 'Cheia trackerului a fost revocată',
            ],
            'settings' => [
                'changed' => 'Setările lucrului în teren au fost modificate',
            ],
            'territories' => [
                'imported' => 'Nomenclatorul de teritorii încărcat',
            ],
            'territory' => [
                'created' => 'Teritoriu adăugat',
                'responsible_assigned' => 'Responsabil de teritoriu numit',
                'responsible_removed' => 'Responsabil de teritoriu eliminat',
                'updated' => 'Teritoriu modificat',
            ],
        ],
        'org' => [
            'manager' => [
                'changed' => 'Schimbat șeful direct',
            ],
            'membership' => [
                'created' => 'Persoană inclusă în subdiviziune',
                'position_changed' => 'Funcție modificată',
                'transferred' => 'Transfer în altă subdiviziune',
            ],
            'unit' => [
                'archived' => 'Subdiviziune arhivată',
                'created' => 'Subdiviziune creată',
                'head_changed' => 'Schimbat conducătorul subdiviziunii',
                'moved' => 'Subdiviziune mutată',
                'territories_changed' => 'Schimbate teritoriile subdiviziunii',
                'updated' => 'Subdiviziune modificată',
            ],
        ],
        'profile' => [
            'open' => [
                'updated' => 'Profil deschis modificat',
            ],
            'layer' => [
                'viewed' => 'Vizualizarea unui strat confidențial',
                'updated' => 'Strat confidențial modificat',
            ],
            'settings' => [
                'updated' => 'Setările profilurilor modificate',
            ],
        ],
        'notes360' => [
            'created' => 'Notă „360” creată',
            'updated' => 'Notă „360” modificată',
            'deleted' => 'Notă „360” ștearsă',
        ],
        'tasks' => [
            'task' => [
                'created' => 'Sarcină creată',
                'updated' => 'Sarcină modificată',
                'people_changed' => 'Schimbați executorii sau observatorii',
                'deleted' => 'Sarcină ștearsă (marcată)',
                'recurred' => 'Creată următoarea repetare a sarcinii',
                'escalated' => 'Sarcină escaladată conducerii',
            ],
            'status' => [
                'changed' => 'Schimbat statusul sarcinii',
            ],
            'checklist' => [
                'changed' => 'Modificată lista de verificare',
            ],
            'dependency' => [
                'added' => 'Adăugată dependența sarcinii',
            ],
            'time' => [
                'logged' => 'Timp înregistrat',
            ],
            'reassignment_suggested' => 'Propusă reatribuirea sarcinii',
            'workflow' => [
                'changed' => 'Modificate tranzițiile statusurilor',
            ],
        ],
        'projects' => [
            'project' => [
                'created' => 'Proiect creat',
                'updated' => 'Proiect modificat',
                'status_changed' => 'Schimbat statusul proiectului',
                'archived' => 'Proiect arhivat',
            ],
            'members' => [
                'changed' => 'Schimbată componența proiectului',
            ],
            'phase' => [
                'changed' => 'Modificată etapa proiectului',
            ],
            'budget' => [
                'changed' => 'Modificat bugetul sau resursele',
            ],
            'template' => [
                'saved' => 'Șablon de proiect salvat',
            ],
        ],
        'custom_fields' => [
            'definition' => [
                'saved' => 'Câmp personalizat salvat',
            ],
        ],
        'crm' => [
            'interaction' => [
                'recorded' => 'Interacțiune înregistrată',
            ],
            'relation' => [
                'added' => 'Legătură între persoane adăugată',
                'removed' => 'Legătură între persoane ștearsă',
            ],
            'duplicate' => [
                'dismissed' => 'Posibilă dublură respinsă',
            ],
            'people' => [
                'merged' => 'Fișe de persoane comasate',
            ],
            'pipeline' => [
                'created' => 'Pâlnie creată',
                'updated' => 'Pâlnie modificată',
            ],
            'lead' => [
                'created' => 'Lead creat',
                'stage_changed' => 'Lead mutat',
                'assigned' => 'Responsabil de lead desemnat',
                'unfrozen' => 'Lead revenit automat în lucru',
            ],
            'leads' => [
                'exported' => 'Export de lead-uri',
            ],
            'appeal' => [
                'registered' => 'Adresare înregistrată',
                'assigned' => 'Responsabil de adresare desemnat',
                'status_changed' => 'Statutul adresării schimbat',
                'task_linked' => 'Sarcină legată de adresare',
                'prioritized' => 'Prioritatea adresării schimbată',
            ],
            'appeals' => [
                'exported' => 'Export de adresări',
            ],
            'segment' => [
                'created' => 'Segment creat',
                'updated' => 'Segment modificat',
                'deleted' => 'Segment șters',
                'tasks_created' => 'Sarcini create după segment',
            ],
            'import' => [
                'uploaded' => 'Fișier de import încărcat',
                'completed' => 'Import efectuat',
                'failed' => 'Import eșuat',
                'rolled_back' => 'Import anulat',
            ],
        ],
        'groups' => [
            'group' => [
                'created' => 'Grup creat',
                'updated' => 'Grup modificat',
                'archived' => 'Grup trimis în arhivă',
            ],
            'member' => [
                'joined' => 'Membru a aderat la grup',
                'left' => 'Membru a părăsit grupul',
                'role_changed' => 'Rolul membrului în grup schimbat',
            ],
            'request' => [
                'submitted' => 'Cerere de aderare la grup depusă',
                'rejected' => 'Cerere de aderare la grup respinsă',
            ],
            'invitation' => [
                'sent' => 'Invitație în grup trimisă',
                'bulk_sent' => 'Invitare în masă în grup',
                'link_created' => 'Link de invitație în grup creat',
                'declined' => 'Invitație în grup refuzată',
                'revoked' => 'Invitație în grup revocată',
            ],
        ],
        'social' => [
            'post' => [
                'created' => 'Postare creată',
                'published' => 'Postare publicată',
                'updated' => 'Postare modificată',
                'audience_changed' => 'Audiența postării schimbată',
                'deleted' => 'Postare ștearsă de autor',
                'pinned' => 'Postare fixată',
                'unpinned' => 'Postare desprinsă',
            ],
            'comment' => [
                'created' => 'Comentariu adăugat',
                'deleted' => 'Comentariu șters de autor',
            ],
            'report' => [
                'created' => 'Reclamație asupra conținutului depusă',
                'dismissed' => 'Reclamație respinsă',
            ],
            'content' => [
                'hidden' => 'Conținut ascuns de moderator',
                'restored' => 'Conținut restabilit',
            ],
            'user' => [
                'warned' => 'Avertisment dat utilizatorului',
                'muted' => 'Restricție aplicată utilizatorului',
                'unmuted' => 'Restricție ridicată',
                'mute_expired' => 'Termenul restricției a expirat',
            ],
            'revisions' => [
                'viewed' => 'Vizualizarea istoricului editărilor postării',
            ],
        ],
        'events' => [
            'event' => [
                'created' => 'Eveniment creat',
                'updated' => 'Eveniment modificat',
                'cancelled' => 'Eveniment anulat',
            ],
            'invitation' => [
                'sent' => 'Invitație la eveniment trimisă',
                'bulk_sent' => 'Invitare în masă la eveniment',
                'withdrawn' => 'Invitație la eveniment retrasă',
            ],
            'rsvp' => [
                'set' => 'Răspuns la invitație',
            ],
            'attendance' => [
                'marked' => 'Prezență marcată',
            ],
            'results' => [
                'published' => 'Rezultatele evenimentului publicate',
            ],
            'reminder' => [
                'sent' => 'Memento despre eveniment trimis',
            ],
            'feed' => [
                'created' => 'Abonare la calendar creată',
                'revoked' => 'Abonare la calendar revocată',
            ],
        ],
        'notifications' => [
            'announcement' => [
                'sent' => 'Anunț trimis',
            ],
            'critical' => [
                'sent' => 'Notificare critică trimisă',
                'acknowledged' => 'Citirea notificării critice confirmată',
            ],
            'defaults' => [
                'changed' => 'Setările implicite de notificare ale rolului modificate',
            ],
        ],
        'files' => [
            'antivirus' => [
                'enabled' => 'Protecția antivirus a fișierelor a fost activată',
                'disabled' => 'Protecția antivirus a fișierelor a fost dezactivată',
            ],
        ],
        'messaging' => [
            'chat' => [
                'created' => 'Chat creat',
                'archived' => 'Chat trimis în arhivă',
                'investigated' => 'Chat citit în cadrul unei investigații',
            ],
            'member' => [
                'added' => 'Membru adăugat în chat',
                'removed' => 'Membru a părăsit chatul',
                'role_changed' => 'Rolul membrului în chat schimbat',
            ],
            'link' => [
                'created' => 'Link de invitație în chat creat',
                'revoked' => 'Link de invitație în chat revocat',
            ],
            'message' => [
                'deleted' => 'Mesaj șters de moderatorul chatului',
            ],
            'attachment' => [
                'infected' => 'Atașament șters de antivirus',
            ],
            'policy' => [
                'changed' => 'Regula mesajelor personale modificată',
            ],
            'retention' => [
                'changed' => 'Termenul de păstrare a mesajelor modificat',
                'purged' => 'Mesaje mai vechi decât termenul de păstrare șterse',
            ],
        ],
    ],
    'on_behalf_of' => 'în numele lui :name',
];
