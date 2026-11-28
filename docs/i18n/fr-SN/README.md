<!-- Source: README.md; SHA256: 813d1c85c236b426b83e42e63c69496f33f8909b5e435d0d45efa6a4ba2f17ca -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

<p align="center">
  <img src="../../images/logo.png" width="300" height="200" alt="Logo de l'extension : un cactus vert devant le contour d'un nuage orange accompagné d'un graphique linéaire, au-dessus du texte Cacti Monitor for Cloudflare">
</p>

# Extension Cloudflare pour Cacti

[![CI](https://github.com/somethingwithproof/plugin_cloudflare/actions/workflows/ci.yml/badge.svg)](https://github.com/somethingwithproof/plugin_cloudflare/actions/workflows/ci.yml)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_cloudflare&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_cloudflare)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](../../../LICENSE)
![PHP >= 7.4](https://img.shields.io/badge/php-%3E%3D7.4-777bb4.svg)
![Cacti >= 1.2.25](https://img.shields.io/badge/cacti-%3E%3D1.2.25-2e7d32.svg)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_cloudflare&metric=coverage)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_cloudflare)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_cloudflare&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_cloudflare)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_cloudflare&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_cloudflare)
![Cloudflare API: read-only](https://img.shields.io/badge/cloudflare%20api-read--only-f38020.svg)

Une extension pour Cacti 1.2 en cours de développement pour superviser les tunnels Cloudflare et les analyses de zones. Elle interroge l'API Cloudflare à l'aide de jetons d'API à portée restreinte, ne modifie jamais rien dans Cloudflare et conserve ses données dans ses propres tables. Dans Cacti, elle copie des fichiers XML de requête de données dans `resource/script_server/` et crée des équipements, des graphiques et des modèles via les propres API de Cacti, sans aucune modification des fichiers du cœur.

**État : 0.4.0-dev, non publiée.** La dernière version publiée est la 0.3.0. La version de développement ajoute l'exportation d'inventaire au format CSV, le retrait explicite des tunnels supprimés, la mise en cache des analyses de zones, un client GraphQL Analytics en lecture seule à liste autorisée (`GraphqlAnalyticsClient`) avec des assistants de synthèse prêts pour Thold (`AnalyticsSummary`), une CLI Configuration-as-Code (`cli/cloudflare.php`), des hooks écosystème (`abilities_register`, `doctor_register_checks`, `casc_register_providers`) et la prise en charge des collecteurs distants limitée au poller (`replicate_out`). La qualification auprès du fournisseur en conditions réelles et la validation de déploiement restent en attente.

Le tableau ci-dessous décrit la version stable 0.3.0. Les ajouts de la version de développement dans 0.4.0-dev sont décrits ci-après dans les sections consacrées aux flux de travail de l'opérateur et aux analyses de zones.

| Disponible actuellement | Prévu |
|---|---|
| Installation, mise à niveau, désactivation et désinstallation via le gestionnaire d'extensions de Cacti | Qualification GraphQL sur compte réel et de déploiement (sortie du Jalon 4) |
| Droits Cloudflare : View, Operator et Configure | Injection de pannes, modèles TLA+ et documentation complète (Jalon 5) |
| Ajout, modification, suppression, activation et désactivation de comptes Cloudflare | |
| Stockage d'un jeton dans un fichier ou chiffré dans la base de données de Cacti | |
| Vérification d'un jeton et contrôle de ses droits de lecture sur les tunnels et les zones | |
| Découverte des tunnels et des connecteurs dans un collecteur en arrière-plan (`poller_cloudflare.php`) | |
| Pages Vue d'ensemble, Tunnels et Connecteurs sous Management > Cloudflare Tunnels | |
| Suivi du cycle de vie : `active`, `unavailable` et `deleted` | |
| Un équipement Cacti par compte, créé par un administrateur, avec les tunnels comme index d'une requête de données | |
| Graphiques Connectors, Health et Collection par tunnel, tracés à partir d'un fichier RRD par tunnel | |
| Les valeurs que l'extension ne peut pas garantir sont enregistrées comme inconnues (`U`), jamais comme 0 | |

## Rôles d'accès

| Domaine | Accès |
| --- | --- |
| Cloudflare: View | Pages de supervision existantes. |
| Cloudflare: Operator | Supervision, plus la page Opérations et les acquittements personnels de triage. |
| Cloudflare: Configure | Accès Operator, plus la configuration existante et les actions d'administration. |

Après avoir copié les fichiers de mise à niveau, ouvrez la page Plugins en tant qu'administrateur pour enregistrer le nouveau domaine. Attribuez explicitement le domaine Operator sous **Configuration > Users** ou dans les groupes d'utilisateurs. Les mises à niveau conservent les identifiants et les autorisations des domaines View et Admin existants. L'accès Admin hérite de l'accès Operator, et l'accès Operator hérite de l'accès View à chaque requête ; cela n'ajoute pas d'autorisations enregistrées aux utilisateurs ou aux groupes. Les paramètres de l'extension nécessitent également l'accès Cloudflare: Configure en plus du droit Settings de Cacti.

La visibilité des comptes Cloudflare correspond au domaine View existant ; elle s'applique à l'échelle du compte. Les identifiants, la configuration, le mappage, la suppression et les autres modifications d'administration restent dans les pages d'administration. Les acquittements ne durent que pendant la session de l'utilisateur courant, pendant une heure au maximum et uniquement tant que la même observation reste fraîche. Toute observation nouvelle ou modifiée les invalide. Ils ne marquent jamais une mesure en échec comme saine et ne suppriment jamais les alertes.

## Pourquoi cette extension

Les opérateurs Cacti qui publient des services via Cloudflare Tunnel ne disposent d'aucune visibilité sur le tunnel dans Cacti. Un tunnel peut perdre l'ensemble de ses connecteurs alors que les hôtes d'origine répondent toujours en SNMP ; les graphiques existants restent ainsi au vert tandis que les utilisateurs rencontrent des erreurs. L'objectif de cette extension est de placer l'état de santé du tunnel et le trafic de la zone à côté des graphiques d'équipements que les opérateurs surveillent déjà, sans donner à Cacti la moindre capacité de modifier Cloudflare.

Trois contraintes orientent la conception :

- **Lecture seule.** L'extension n'appelle que des points de terminaison `GET` et l'API GraphQL Analytics. Elle n'a besoin d'aucun droit de modification sur aucune ressource Cloudflare.
- **Optionnelle.** Les pannes de Cloudflare, les jetons expirés et les limites de débit ne doivent ni ralentir l'interface Web de Cacti ni bloquer la collecte des autres équipements. Tous les appels API disposent de délais d'expiration stricts et la collecte s'exécute dans un processus en arrière-plan lancé par le poller.
- **Aucune modification du cœur.** L'extension s'appuie sur les points d'extension (hooks) des plugins de Cacti 1.2 et sur ses propres tables. Elle copie un fichier XML de requête de données dans `resource/script_server/` et crée les équipements, les graphiques et les modèles via les propres API de Cacti. Aucune modification des fichiers du cœur n'est effectuée, de sorte que les mises à niveau de Cacti et les autres extensions ne sont pas affectées.

## Fonctionnement

```mermaid
flowchart LR
    subgraph Cacti["Cacti 1.2"]
        UI["Page Comptes<br/>(domaine Configure)"]
        Poller["poller.php<br/>hook poller_bottom"]
        Viewer["Vue d'ensemble, Tunnels, Connecteurs<br/>(domaine View)"]
        Collector["poller_cloudflare.php<br/>collecteur en arrière-plan"]
        SS["ss_cloudflare.php<br/>requête de données script server"]
        Objects[("équipement, graphiques,<br/>sources de données, RRD")]
        DB[("tables<br/>plugin_cloudflare_*")]
    end
    subgraph Plugin["plugin src/"]
        Svc["AccountService<br/>autorisation, validation"]
        Vault["TokenVault / TokenFile"]
        Client["CloudflareClient<br/>classes d'erreur, nouvelles tentatives bornées"]
        Transport["CurlTransport<br/>liste d'autorisation, TLS, délais"]
        Disc["DiscoveryService"]
        Reader["ObservationReader"]
    end
    CF["api.cloudflare.com"]

    UI --> Svc
    Svc --> Vault
    Svc --> DB
    Svc --> Client
    Client --> Transport --> CF
    Viewer --> DB
    Poller -- "exec_background" --> Collector --> Disc
    Disc --> DB
    Disc --> Client
    Collector -- "API Cacti" --> Objects
    Poller -- "script server" --> SS --> Reader --> DB
```

- **La colle Cacti** (`setup.php`, `includes/`, les scripts de pages) enregistre les hooks et les domaines, génère le rendu des pages avec les assistants propres à Cacti et délègue chaque action à la bibliothèque dans `src/`.
- **La bibliothèque** (`src/`, espace de noms `Somethingwithproof\Cloudflare\`) ne dépend d'aucune variable globale de Cacti ; elle est ainsi testée avec une véritable base de données et un faux transport HTTP sans instance Cacti en cours d'exécution.
- **Un seul chemin sortant.** `CurlTransport` est le seul code qui accède au réseau et il n'accepte que `https://api.cloudflare.com`. Chaque défaillance est associée à une classe d'erreur (`auth`, `permission`, `rate_limited`, `transient`, ...), qui détermine le statut affiché dans l'interface.
- **Le stockage** passe par la propre connexion PDO de Cacti avec des requêtes préparées. Les évolutions du schéma sont des migrations numérotées et idempotentes.

Les choix de conception et leurs justifications sont présentés dans
[docs/design.md](../../design.md).

## Captures d'écran

Les captures d'écran proviennent d'une pile de test et non d'un compte Cloudflare en production. Cette pile exécute Cacti 1.2.31 face à une fausse API Cloudflare qui renvoie des données fictives ; le compte, son identifiant et les trois tunnels sont donc des exemples fictifs.

![La liste des comptes Cloudflare avec un compte, « Example account », dont le statut est ok.](../../images/accounts-list.png)

*La liste des comptes Cloudflare. La fausse API accepte le jeton, donc Verify indique `ok`.*

![Le formulaire de modification de compte avec le nom, l'identifiant du compte, le stockage chiffré du jeton, les options de collecte dont Create Graphs, et le tableau Connection Status.](../../images/account-edit.png)

*Modification d'un compte. Les champs de jeton sont toujours vides ; le jeton stocké n'est jamais renvoyé au navigateur. Collect Tunnels et Create Graphs sont activés et Collect Analytics est désactivé. Connection Status affiche le résultat de la dernière vérification (Verify) : `ok`, lecture des tunnels accordée et lecture des zones manquante.*

![L'onglet Cloudflare dans les paramètres de Cacti avec le chemin du fichier de clé, deux délais d'expiration et le niveau de journalisation.](../../images/settings-cloudflare.png)

*Settings > Cloudflare.*

![L'onglet Overview avec une ligne pour Example account : statut Down, 3 tunnels actifs, aucun indisponible ni supprimé, l'heure de la dernière découverte réussie et 0 échec consécutif.](../../images/overview.png)

*Management > Cloudflare Tunnels > Overview. Le statut correspond au pire état Cloudflare parmi les tunnels actifs du compte, ici `down` car un tunnel fictif est hors ligne. `Disabled`, `Not collected`, `Collection failing` et `Not yet collected` ont priorité sur l'état de santé des tunnels, dans cet ordre ; ainsi, un compte dont la dernière exécution a échoué affiche `Collection failing` quel que soit l'état signalé par ses tunnels.*

![L'onglet Tunnels listant trois tunnels de Example account : blog (healthy), old-office (down) et staging (inactive), tous actifs.](../../images/tunnels-list.png)

*L'onglet Tunnels. Connectors et Connections affichent `-` pour old-office, car la fausse API répond à sa requête de connexions comme pour un tunnel inconnu et rien n'a été enregistré, et 0 pour staging, qui ne dispose d'aucun connecteur en cours d'exécution.*

![Un graphique Cacti intitulé Cloudflare: Example account - blog - Connectors. Les courbes Connectors, Connections et Connector Versions se maintiennent à 1, s'interrompent pendant une coupure d'environ une minute et demie, puis reprennent à 1.](../../images/tunnel-graph.png)

*Le graphique Connectors du tunnel blog. Les trois courbes valent 1 avant et après la coupure au milieu, où la fausse API a renvoyé des erreurs et l'extension a enregistré des valeurs inconnues. Les courbes ne retombent pas à 0. Le graphique provient de la même pile de données fictives (`make screenshots`), qui définit un pas de 30 secondes sur cette unique source de données afin que sept minutes de collecte suffisent à remplir le graphique.*

## Prérequis

- Cacti 1.2.25 à 1.2.31. Cacti 1.2.31 nécessite lui-même PHP 8.0 ou une version ultérieure.
  L'intégration continue (CI) teste uniquement la version 1.2.31 ; la version 1.2.25 a été testée manuellement pour la dernière fois le 2026-10-22.
- PHP 7.4 à 8.4 avec les extensions `curl`, `openssl` et `pdo_mysql`.
  Le plugin refuse de s'activer sans elles.
- MariaDB 10.6 ou une version ultérieure, ou MySQL 8.0 ou une version ultérieure.
- Un accès HTTPS depuis le serveur Cacti vers `api.cloudflare.com`. Les proxys ne sont pas
  pris en charge.

Consultez [docs/compatibility.md](../../compatibility.md) pour connaître les configurations testées.

## Installation

1. Placez le plugin dans le répertoire `plugins/cloudflare` de Cacti, soit en
   extrayant l'archive depuis
   [GitHub Releases](https://github.com/somethingwithproof/plugin_cloudflare/releases)
   (l'exécution de `make package` dans une copie locale du tag de version génère la même
   archive), soit par un clonage git.
   Le répertoire doit impérativement se nommer `cloudflare`.
2. Installez-le et activez-le depuis Console > Configuration > Plugins, ou via la
   ligne de commande (CLI) en tant qu'utilisateur du serveur web :

   ```sh
   php cli/plugin_manage.php --plugin=cloudflare --install --enable
   ```

3. L'utilisateur administrateur reçoit la permission Cloudflare: Configure lors de l'installation. Accordez-la
   à d'autres utilisateurs ou groupes sous Console > Configuration > Users.
4. Créez un jeton disposant des permissions décrites dans
   [docs/token-permissions.md](../../token-permissions.md). En résumé :
   Account > Cloudflare Tunnel > Read, et éventuellement Zone > Zone > Read.
5. Stockez le jeton selon l'une des deux méthodes ci-dessous, puis ajoutez le compte sous
   Console > Configuration > Cloudflare Accounts et exécutez Verify depuis le
   menu d'actions.

### Option A : chiffrement dans la base de données

Le plugin chiffre les jetons à l'aide d'une clé de 32 octets que vous créez. Il ne
crée ni n'écrit jamais la clé lui-même. Le fichier doit être lisible par les utilisateurs
du serveur web et du poller, ne doit pas être lisible par les autres utilisateurs, et son répertoire
doit permettre à ces utilisateurs d'y accéder. Pour un serveur web exécuté en tant que `www-data` :

```sh
sudo mkdir -p /etc/cacti
sudo sh -c 'umask 077 && openssl rand -hex 32 > /etc/cacti/cloudflare.key'
sudo chgrp www-data /etc/cacti/cloudflare.key
sudo chmod 0440 /etc/cacti/cloudflare.key
```

Le chemin par défaut est `/etc/cacti/cloudflare.key` ; modifiez-le sous Settings >
Cloudflare > Token Vault Key File. Lorsque la clé est absente ou refusée, le
formulaire d'édition propose uniquement l'option du fichier de jeton. Conservez une sauvegarde de la clé :
sans elle, les jetons stockés ne peuvent pas être déchiffrés et doivent être saisis à nouveau.

#### Rotation de la clé

Il n'existe aucune commande de rechiffrement. Une nouvelle clé ne peut pas déchiffrer les jetons stockés avec
l'ancienne, la rotation implique donc de saisir à nouveau chaque jeton stocké :

1. Ayez la valeur du jeton de chaque compte à portée de main. Cloudflare n'affiche la valeur d'un jeton
   que lors de sa création ou de son renouvellement ; renouvelez donc le jeton dans le tableau de bord
   Cloudflare si vous ne l'avez plus.
2. Créez la nouvelle clé à un nouvel emplacement avec les commandes ci-dessus, par exemple
   `/etc/cacti/cloudflare.key.new`, avec le même propriétaire et les mêmes droits.
3. Définissez Settings > Cloudflare > Token Vault Key File sur le nouveau chemin et enregistrez.
   Désormais, l'action Verify sur un compte encore chiffré avec l'ancienne clé
   signale « Stored token cannot be decrypted », et chaque exécution du collecteur journalise
   `discovery token unavailable` avec `reason=decrypt_failed` pour ce compte.
4. Pour chaque compte qui stocke son jeton dans la base de données, ouvrez-le sous
   Console > Configuration > Cloudflare Accounts, collez le jeton dans les deux
   champs de jeton, enregistrez et exécutez Verify. L'opération doit renvoyer `ok`.
5. Une fois que tous ces comptes sont vérifiés avec succès, supprimez l'ancien fichier de clé et ses
   sauvegardes.

Entre les étapes 3 et 4, les tunnels d'un compte ne sont pas actualisés et leurs
graphiques de santé, de connecteurs et de connexions enregistrent U. Les comptes utilisant un fichier de jeton
ne sont pas affectés.

### Option B : fichier de jeton

Écrivez le jeton, et rien d'autre, dans un fichier que les utilisateurs du serveur web et du poller
peuvent lire mais pas les autres utilisateurs, puis saisissez son chemin absolu dans le formulaire d'édition :

```sh
sudo sh -c 'umask 077 && cat > /etc/cacti/cloudflare-account.token'   # paste the token, then Ctrl-D
sudo chgrp www-data /etc/cacti/cloudflare-account.token
sudo chmod 0440 /etc/cacti/cloudflare-account.token
```

Le fichier est vérifié lors de sa lecture, au moment de l'action Verify, et non lors de l'enregistrement. Les liens
symboliques, les fichiers de plus de 4 KiB et les fichiers accessibles en lecture ou en écriture par d'autres utilisateurs sont
refusés.

Toute personne disposant de la permission Cloudflare: Configure peut saisir n'importe quel chemin, et l'utilisateur du poller
le lit. Pour conserver les fichiers de jetons au même endroit, définissez Settings > Cloudflare >
Token File Directory, par exemple sur `/etc/cacti` ; tout chemin situé en dehors de ce répertoire est alors
refusé. Consultez [docs/security.md](../../security.md#token-file).

## Mise à niveau

1. Remplacez les fichiers dans `plugins/cloudflare` par ceux de la nouvelle version, en conservant
   le nom du répertoire.
2. Ouvrez Console > Configuration > Plugins, ou Console > Configuration >
   Cloudflare Accounts, une fois en tant qu'administrateur. Cela exécute les migrations du plugin,
   importe les modèles Cloudflare, copie le fichier de requête de données (voir
   [Métriques et graphiques des tunnels](#tunnel-metrics-and-graphs)), enregistre les
   hooks écosystème `abilities_register`, `doctor_register_checks`,
   `casc_register_providers` et `replicate_out` (`includes/ecosystem.php`) et, lors d'une
   mise à niveau depuis la version 0.1.0, enregistre le hook `poller_bottom`.

`poller.php` n'exécute pas la mise à niveau du plugin ; ainsi, jusqu'à l'étape 2, le nouveau
schéma est absent. Chaque exécution du collecteur ne fait alors rien et journalise
`CLOUDFLARE: collector skipped; plugin upgrade pending, open Console > Plugins`.
Les pages Cloudflare Tunnels nécessitent uniquement Cloudflare: View ; elles n'exécutent donc jamais
la mise à niveau et, jusqu'à l'étape 2, elles peuvent afficher « The Cloudflare tunnels could not
be read ».

## Statut du compte

L'action Verify définit le statut du compte :

| Statut | Signification |
|---|---|
| `unknown` | Non vérifié depuis l'ajout du compte ou la modification de ses identifiants |
| `ok` | Le jeton est actif et peut lire les tunnels |
| `token_inactive` | Cloudflare signale le jeton comme désactivé ou expiré |
| `auth_failed` | Cloudflare a rejeté le jeton comme invalide ou expiré (HTTP 401) |
| `permission_denied` | Le jeton est actif mais ne peut pas lire les tunnels, ou il ne dispose pas des permissions nécessaires pour appeler les points de terminaison de vérification du jeton (HTTP 403) |
| `unreachable` | L'API n'a pas pu être jointe, a expiré ou a renvoyé une erreur serveur |
| `rate_limited` | Cloudflare a renvoyé HTTP 429 |
| `error` | Tout autre échec, y compris un fichier de jeton manquant ou non sécurisé, un fichier de clé du coffre-fort de jetons manquant ou inutilisable, ou un jeton stocké qui ne peut pas être déchiffré |

La page d'édition indique également si la lecture des tunnels et la lecture des zones ont été accordées.

## Découverte des tunnels

Après chaque cycle d'interrogation sur le poller collecteur configuré (`cloudflare_poller_id`, `1` par défaut), le hook `poller_bottom` démarre
un collecteur en arrière-plan avec `exec_background` lorsqu'au moins un compte activé
dispose de l'option Collect Tunnels activée. Le collecteur ne s'exécute jamais dans le processus
du poller, afin qu'une API Cloudflare lente ne retarde pas `poller.php`. `INFO` déclare
`remote_collect:1`, et le hook `replicate_out` (`RemoteReplication`) pousse les
tables de métadonnées non secrètes (`plugin_cloudflare_accounts` avec `token_ciphertext`
remplacé par `NULL`, `plugin_cloudflare_tunnels`, `plugin_cloudflare_connectors`,
`plugin_cloudflare_zones`, `plugin_cloudflare_metric_mappings` et
`plugin_cloudflare_resources`) vers les pollers distants (`poller_id > 1`) tout en
excluant le matériel de clé du coffre.

Pour chaque compte arrivé à échéance, le collecteur liste les tunnels du compte, puis
lit les connexions de ses tunnels actifs, ce qui fournit les connecteurs de chaque tunnel,
leurs versions, leurs centres de données et leurs adresses d'origine. Les comptes arrivent
à échéance à chaque Discovery Interval (300 secondes par défaut). Une exécution ne démarre aucun nouveau
compte après 50 secondes et effectue au maximum 200 requêtes de connexion. L'une ou l'autre
de ces limites peut également interrompre un compte en cours de lecture de ses connexions ; ce
compte est alors enregistré comme `partial`, et l'exécution suivante reprend avec les tunnels
tentés le moins récemment. Les comptes qui n'ont pas été démarrés restent en attente pour l'exécution suivante.

Un tunnel possède un cycle de vie, distinct du statut signalé par Cloudflare
(`healthy`, `degraded`, `down` ou `inactive`) :

| Cycle de vie | Signification |
|---|---|
| `active` | Le tunnel figurait dans la dernière liste complète |
| `deleted` | Une liste complète ne l'incluait pas, y compris une liste vide. La ligne et son historique sont conservés, et le tunnel redevient `active` s'il réapparaît |
| `unavailable` | Trois pannes comptabilisées sans liste complète entre elles laissent l'état du tunnel inconnu |

Seule une liste complète et réussie ajoute, met à jour ou supprime des tunnels, et une
liste en échec n'en supprime jamais aucun. Une impossibilité totale de lire Cloudflare
(`transient`, `rate_limited`, `auth` ou `permission`) compte comme une panne ;
après trois pannes comptabilisées sans liste complète entre elles, les
tunnels actifs du compte deviennent `unavailable`. La liste complète suivante
les restaure et réinitialise le compteur. Un tunnel que Cloudflare ne reconnaît plus
lors de la lecture de ses connexions est ignoré sans faire échouer le compte. Les autres
échecs n'incrémentent ni ne réinitialisent le compteur de pannes.

Dans `cacti.log`, les lignes de découverte commencent par `CLOUDFLARE: discovery` et le résumé
d'exécution par `CLOUDFLARE: collector`. Les échecs et les changements de cycle de vie, tels
qu'une liste en échec ou des tunnels marqués `unavailable`, sont journalisés au niveau par défaut
`LOW` de Cacti ; le résultat de chaque compte et le résumé d'exécution sont journalisés au niveau
`MEDIUM`. Les lectures de connexion peuvent échouer sur chaque tunnel ; par conséquent, pour chaque compte,
seul le premier échec de chaque classe d'erreur est journalisé au niveau `LOW`, les suivants au niveau
`MEDIUM`, suivis d'une ligne `LOW` par classe indiquant le nombre de tunnels ayant
échoué.

Management > Cloudflare Tunnels nécessite la permission Cloudflare: View et est en
lecture seule :

- **Overview** liste chaque compte avec son statut, le nombre de tunnels par cycle de vie,
  la dernière découverte réussie et le nombre d'échecs consécutifs.
- **Tunnels** liste les tunnels sur l'ensemble des comptes ou pour un seul compte, avec des filtres
  par compte, cycle de vie, statut et nom, ainsi que des colonnes triables.
- **Connectors** affiche les connecteurs du tunnel sélectionné dans l'onglet Tunnels.

<a name="tunnel-metrics-and-graphs"></a>

## Métriques et graphiques des tunnels

Cacti lit l'état des tunnels via une requête de données de serveur de scripts,
`ss_cloudflare.php`. Elle répond uniquement à partir des tables du plugin et n'appelle jamais
Cloudflare, de sorte qu'une panne de Cloudflare ne peut pas ralentir le poller. Chaque tunnel fournit
six valeurs : la santé (healthy 3, degraded 2, down 1, inactive 0), les connecteurs,
les connexions, les versions des connecteurs, le nombre de secondes écoulées depuis la dernière lecture
de ses connexions et le nombre d'échecs consécutifs de la tâche de découverte. La santé, les connecteurs,
les connexions et les versions ne sont affichés que lorsque le plugin peut en garantir la validité,
ce qui signifie que la dernière découverte a réussi, que le tunnel est `active` et que la
mesure est récente. Dans le cas contraire, leur valeur est `U`, et le graphique affiche un vide au lieu
d'un zéro qui ressemblerait à une panne. Les deux valeurs de collecte restent
affichées pendant une panne, afin que le graphique Collection la reflète ; elles ne valent `U`
que pour un tunnel supprimé ou dont les connexions n'ont jamais été actualisées. Les règles
sont détaillées dans [docs/metrics.md](../../metrics.md).

### Création de l'équipement

Un compte correspond au plus à un équipement Cacti. L'équipement contient les tunnels
du compte en tant qu'index de la requête de données Cloudflare Tunnels. Rien ne le crée
automatiquement :

1. Ouvrez Console > Configuration > Cloudflare Accounts.
2. Sélectionnez le compte, choisissez Create Cacti Device dans le menu d'actions, puis
   confirmez.

Cette action nécessite à la fois le domaine Cloudflare: Configure et le domaine
Sites/Devices/Data de Cacti (celui de `host.php`). L'entrée de menu n'apparaît
qu'aux utilisateurs qui détiennent les deux, et le serveur refuse la requête de toute autre personne.
L'équipement est nommé `Cloudflare: <account name>`, utilise le modèle d'hôte Cloudflare Account
et le poller principal, a la version SNMP 0 et aucune vérification de
disponibilité, et reçoit le nom d'hôte `<account ID>.cloudflare.invalid`. Ce nom est
réservé et ne se résout jamais ; Cacti ne s'y connecte pas. Si le compte
dispose déjà d'un équipement, l'action l'indique et ne modifie rien. Si un
administrateur supprime l'équipement dans Cacti, le plugin n'en crée pas d'autre
tant que Create Cacti Device n'est pas exécuté à nouveau.

### Création des graphiques

L'option Create Graphs sur le formulaire du compte est désactivée par défaut. Lorsqu'elle est activée, chaque fois
qu'une découverte du compte se termine par `ok` ou `partial`, le collecteur réindexe
l'équipement et crée tous les graphiques manquants pour les tunnels `active` du compte :
Connectors, Health et Collection, soit trois par tunnel. Les trois graphiques d'un tunnel
partagent un même fichier RRD. Une exécution crée au maximum 50 graphiques et s'arrête après 20
secondes, puis l'exécution suivante reprend. Le plugin ne supprime jamais d'objet Cacti.
Un graphique qu'un administrateur supprime n'est pas recréé, et un tunnel que
Cloudflare ne liste plus conserve son graphique et son fichier RRD, qui affichent `U`.

L'activation de Create Graphs nécessite les deux domaines. Sa désactivation nécessite uniquement
Configure. Un utilisateur sans le domaine Sites/Devices/Data qui enregistre le formulaire
laisse le paramètre tel qu'il était, avec un avertissement. Lorsque Create Graphs est désactivé, l'équipement
est tout de même réindexé après chaque découverte, afin que les nouveaux tunnels apparaissent comme
index de la requête de données.

### Le fichier de requête de données

Cacti 1.2.31 lit un fichier XML de requête de données uniquement depuis l'intérieur de son répertoire
`resource`. Lors de l'installation et de la mise à niveau, le plugin copie donc un fichier,
`templates/resource/cloudflare_tunnels.xml`, vers
`<Cacti>/resource/script_server/cloudflare_tunnels.xml`. C'est le seul fichier
que le plugin écrit en dehors de son propre répertoire ; l'utilisateur qui installe ou
met à niveau le plugin doit donc pouvoir écrire dans ce répertoire.
La désinstallation conserve le fichier, ainsi que les équipements, les graphiques, les fichiers RRD et
les modèles. Si la copie ou l'importation du modèle échoue, le plugin le journalise et
Create Cacti Device réessaie.

## Paramètres

Paramètres > Cloudflare contient :

- **Fichier clé du coffre de jetons** : chemin d'accès vers la clé du coffre.
- **Répertoire des fichiers de jetons** : lorsqu'il est défini, tout fichier de jeton situé en dehors de ce répertoire est refusé. Vide par défaut, ce qui autorise n'importe quel chemin. Voir [docs/security.md](../../security.md#token-file).
- **Délai d'expiration des requêtes interactives** : durée totale en secondes accordée à une requête effectuée depuis l'interface web, telle que Vérifier. Valeur par défaut : 10 ; toute valeur en dehors de la plage de 5 à 60 revient à 10.
- **Délai d'expiration des requêtes du collecteur** : durée totale en secondes accordée à une requête effectuée par le collecteur en arrière-plan. Valeur par défaut : 20 ; toute valeur en dehors de la plage de 5 à 60 revient à 20.
- **Niveau de journalisation** : enregistré. Rien ne le lit encore.

Dans le formulaire de compte, les options Collecter les tunnels et Intervalle de découverte contrôlent la découverte, et Créer les graphiques contrôle la création des graphiques. L'option Collecter les analyses est enregistrée et n'a aucun effet dans cette version.

## Langues

Le plugin inclut des traductions en espagnol (Espagne, `es-ES`), français (`fr-FR`), allemand (`de-DE`), chinois simplifié (`zh-CN`), hindi (`hi-IN`), arabe (`ar-SA`) et japonais (`ja-JP`). Cacti les affiche une fois la prise en charge des langues activée (Configuration > Paramètres > Général > Prise en charge des langues) et après qu'un utilisateur a sélectionné la langue. Un catalogue espagnol d'Amérique latine (`es-419`) est également fourni, mais Cacti 1.2 ne dispose d'aucun paramètre régional espagnol d'Amérique latine à sélectionner ; il ne prendra donc effet que lorsque Cacti en ajoutera un. En attendant, choisissez l'espagnol (`es-ES`).

Certains textes restent en anglais : les valeurs d'état que le plugin enregistre et affiche telles quelles (`ok`, `active`, `unavailable`, `deleted`, ainsi que l'état du tunnel signalé par Cloudflare), et tout ce que le plugin écrit dans le journal de Cacti.

## Désinstallation et rétention des données

La désinstallation supprime les tables propres au plugin, qui contiennent les comptes, les jetons chiffrés, les tunnels découverts, l'état des tâches et l'enregistrement des objets Cacti qu'il a créés, et efface ses paramètres `cloudflare_`. Elle ne supprime ni le fichier clé, ni les fichiers de jetons, ni le fichier copié `resource/script_server/cloudflare_tunnels.xml`, ni aucun équipement, graphique, source de données, fichier RRD ou modèle Cacti. Ces éléments conservent les données historiques de supervision, et leur suppression nécessite une action explicite de l'administrateur dans Cacti.

L'équipement conservé cesse de recevoir des données. Après une désinstallation, ou après la suppression de son compte, aucun compte ne possède plus l'hôte ; ses graphiques conservent donc leur historique mais affichent `U` à partir de ce moment. L'action Créer un équipement Cacti sur le compte réinstallé ou rajouté crée un nouvel équipement ; elle ne réutilise pas l'ancien, il convient donc de supprimer l'ancien équipement dans Cacti lorsque son historique n'est plus nécessaire.

## Limitations connues

- Une exécution effectue au maximum 200 requêtes de connexion. Les tunnels au-delà de ce plafond sont actualisés moins souvent que la fenêtre d'observation, de sorte que leurs graphiques de connexion présentent des lacunes.
- Le plugin écrase les modifications apportées par l'administrateur aux modèles importés lorsqu'une mise à niveau modifie le fichier XML de modèle fourni.
- Les pollers distants reçoivent les métadonnées répliquées sans identifiants (`token_ciphertext` est remplacé par `NULL` et les clés du coffre ne sont jamais répliquées). Lorsque le collecteur s'exécute sur un poller distant désigné (`cloudflare_poller_id`), provisionnez directement les fichiers de jetons ou une clé du coffre sur cet hôte collecteur.
- Les tunnels supprimés restent dans l'index de requête de données et affichent `U` jusqu'à ce qu'un administrateur les retire explicitement. Le retrait supprime uniquement les graphiques enregistrés par le plugin et les sources de données non partagées via Cacti ; la ligne d'inventaire reste visible. Cacti contrôle le nettoyage des fichiers RRD.
- Cacti désactive un plugin dont le code se termine par une véritable erreur fatale, telle qu'un épuisement de la mémoire. Voir [docs/security.md](../../security.md).

## Sécurité

- Les clés API globales sont refusées ; seuls les jetons API à portée limitée sont acceptés.
- Les jetons sont stockés dans un fichier que vous provisionnez ou chiffrés avec AES-256-GCM sous un fichier clé qui n'entre jamais dans la base de données. Ils ne sont jamais affichés sur une page, écrits dans un journal ni inclus dans un message d'erreur.
- Les erreurs de base de données ne contiennent que le SQLSTATE et le code d'erreur du pilote, jamais les valeurs de la requête.
- Chaque modification est une requête POST protégée par le jeton CSRF de Cacti. Les modifications administratives de compte et de configuration requièrent l'autorisation Cloudflare : Configurer ; les acquittements personnels de triage requièrent Cloudflare : Opérateur. La création d'un équipement Cacti ou l'activation de Créer les graphiques nécessite également l'autorisation Sites/Équipements/Données de Cacti. Un plugin désactivé refuse l'accès à ses pages.
- Configuration-as-Code (`cli/cloudflare.php` et `casc_register_providers`) n'accepte que les références de secrets `vault:<account_id>` ou `file:<absolute_path>`, rejette les jetons bruts, les textes chiffrés du coffre et les déclarations XML `DOCTYPE`, et exige un `--confirm=<PLAN_HASH>` exact avant d'écrire.
- Les capacités enregistrées (`cloudflare-list-tunnels`, `cloudflare-get-health`) sont strictement en lecture seule, évaluent le domaine Cloudflare: View directement sur les tables d'authentification de Cacti pour l'identifiant utilisateur de l'appelant (jamais sur l'état de session) et ne renvoient ni jetons ni matériel de clé.

Les détails et les limitations connues figurent dans [docs/security.md](../../security.md).
Signalez les vulnérabilités en privé comme décrit dans [SECURITY.md](../../../SECURITY.md).

## Flux de travail des opérateurs et analyses de zones

Les ajouts en cours de développement dans `0.4.0-dev` incluent le retrait explicite des tunnels supprimés, les analyses GraphQL de zones et de tunnels adaptées aux capacités, des assistants de synthèse prêts pour Thold et des modèles Thold importables, une CLI CasC et d'état de santé, des hooks écosystème et la réplication sur collecteurs distants. Ces ajouts ne disposent pas encore d'étiquette de version ni de qualification sur compte réel. Consultez [docs/analytics-and-alerting.md](../../analytics-and-alerting.md) pour la configuration, la sémantique des métriques et les limites de vérification.

- **Client GraphQL Analytics et synthèse Thold (`src/Api/GraphqlAnalyticsClient.php`, `src/Metrics/AnalyticsSummary.php`)** : `GraphqlAnalyticsClient` exécute uniquement des requêtes en lecture seule figurant sur liste autorisée à la compilation (`zone_settings`, `zone_http_metrics`, `zone_edge_latency`, `tunnel_analytics`) via `CloudflareClient` (TLS vérifié, épinglé sur `api.cloudflare.com`, réponses bornées et temporisation de limite de débit `RetryPolicy`) pour collecter le volume de requêtes de zone et de tunnel, les taux d'erreur HTTP 5xx, les octets de réponse et la latence en périphérie (moyenne et p95). `AnalyticsSummary` fournit `recommendedThresholds()` aligné sur les noms DS RRD du plugin (`health`, `collect_age`, `error_pct`, `age`), `summarizeZones()` et `evaluateSample()`.
- **CLI opérateur et CasC (`cli/cloudflare.php`)** : s'appuie sur `ConfigCodec` (`src/Config/ConfigCodec.php`) et `ConfigService` (`src/Config/ConfigService.php`), et prend en charge `cli/cloudflare.php export [--format=yaml|xml] [--file=PATH]` (n'émettant que des références de secrets `vault:` / `file:`), `cli/cloudflare.php validate --file=PATH [--format=yaml|xml]`, `cli/cloudflare.php plan --file=PATH [--format=yaml|xml]`, `cli/cloudflare.php apply --file=PATH --confirm=<PLAN_HASH> [--format=yaml|xml]` et `cli/cloudflare.php health [--format=text|json]`.
- **Hooks d'intégration écosystème (`includes/ecosystem.php`, `setup.php`)** :
  - `abilities_register` (`AbilitiesProvider`) : enregistre `cloudflare-list-tunnels` et `cloudflare-get-health` en lecture seule, restreints au domaine Cloudflare: View (autorisations directes et par groupe), sans aucun jeton ni secret en sortie.
  - `doctor_register_checks` (`DoctorChecks`) : expose `cloudflare_vault_key` (validation du mode, des liens symboliques et de la longueur), `cloudflare_token_dir` (vérifications de chemin absolu, de liens symboliques et d'écriture publique), `cloudflare_tunnel_freshness` et `cloudflare_api_failure_rate`.
  - `casc_register_providers` (`CascProvider`) : expose le fournisseur CasC `cloudflare` (`export`, `validate`, `plan`, `apply`) pour les comptes (références de secrets uniquement), les zones, les tunnels et les mappages de métriques.
  - `replicate_out` (`RemoteReplication`) : réplique les tables de métadonnées non secrètes vers les pollers distants (`poller_id > 1`) avec `token_ciphertext` remplacé par `NULL` et exclusion du matériel de clé du coffre.

### Exportations d'inventaire

Les pages Tunnels et Connecteurs proposent l'option Exporter pour le domaine Cloudflare : Affichage. Les fichiers CSV des tunnels incluent chaque ligne correspondante, en utilisant les paramètres actifs de compte, de cycle de vie, d'état, de recherche et de tri sur toutes les pages. Les fichiers CSV des connecteurs incluent les versions, l'architecture, les centres de données et les adresses d'origine pour le tunnel sélectionné. Les téléchargements lisent uniquement les enregistrements mis en cache. Les valeurs numériques inconnues sont vides ; les cellules ressemblant à des formules sont préfixées par une apostrophe pour une importation sûre dans un tableur.

## Feuille de route

Chaque jalon est publié sous forme de version 0.x numérotée d'après lui : le jalon 3 correspond à la version 0.3.0, le jalon 4 correspondra à la version 0.4.0. Les jalons 1 et 2 n'ont pas été publiés séparément et font partie de la version 0.3.0. La version 1.0.0 est réservée à la version qui achève tous les jalons ci-dessous.

| Jalon | Portée | État |
|---|---|---|
| 1. Fondations | Cycle de vie du plugin, autorisations, configuration de compte, test de connexion | Publié dans la 0.3.0 ([#2](https://github.com/somethingwithproof/plugin_cloudflare/pull/2) à [#5](https://github.com/somethingwithproof/plugin_cloudflare/pull/5)) |
| 2. Découverte | Découverte des tunnels et des connecteurs dans un collecteur en arrière-plan, pages Vue d'ensemble, Tunnels et Connecteurs | Publié dans la 0.3.0 ([#6](https://github.com/somethingwithproof/plugin_cloudflare/pull/6)) |
| 3. Métriques | Sources de données d'état des tunnels, modèles de graphiques, gestion des pannes qui n'enregistre jamais de zéro fabriqué | Publié dans la 0.3.0 ([#7](https://github.com/somethingwithproof/plugin_cloudflare/pull/7)) |
| 4. Analyses et alertes | Analyses GraphQL adaptées au forfait (`GraphqlAnalyticsClient`), synthèse et modèles Thold (`AnalyticsSummary`), CLI CasC (`cli/cloudflare.php`), hooks écosystème et réplication distante | Implémenté dans 0.4.0-dev ; qualification sur compte réel en attente |
| 5. Durcissement | Injection de pannes, modèles TLA+ de découverte et de collecte, documentation complète | Planifié |

## Développement

Les outils requièrent PHP 8.x et Composer ; le code du plugin lui-même reste compatible PHP 7.4. Les tests d'intégration et BDD nécessitent une base de données, que `make db-up` démarre dans Docker sur le port 33061.

```sh
composer install
make db-up
make ci            # unit, integration, BDD, PHPStan, php-cs-fixer, php -l on PHP 7.4
make smoke         # installs the plugin into Cacti 1.2.31 in Docker and walks its lifecycle
make package       # builds build/cloudflare-<version>.tar.gz and checks its contents
make db-down
```

`make smoke` et `make screenshots` nécessitent un dépôt Cacti extrait ; définissez `CACTI_REPO` sur son chemin d'accès. Les tests d'intégration lisent `CF_TEST_DSN`, `CF_TEST_DB_USER` et `CF_TEST_DB_PASS`, et sont ignorés lorsque la base de données est inaccessible, sauf si `CI` est défini.

## Contribution

Les pull requests sont les bienvenues. Chaque commit nécessite une signature DCO (`git commit -s`). Écrivez d'abord le test ; `make ci` doit réussir, et les modifications apportées à l'intégration Cacti doivent également réussir `make smoke`. Le code du plugin doit rester compatible avec PHP 7.4 et suivre le style de code de Cacti (tabulations, guillemets simples, accolades sur la même ligne).

### Traductions

Les messages sont marqués avec `__('...', 'cloudflare')`. `locales/build_gettext.sh` (`make i18n`, qui nécessite GNU gettext) régénère le modèle `locales/po/cacti.pot`, le fusionne dans chaque catalogue `locales/po/<locale>.po` et compile `locales/LC_MESSAGES/<locale>.mo` ; commitez les trois. La CI exécute `make i18n-check`, qui échoue lorsqu'une entrée manque dans un catalogue ou est marquée comme approximative (fuzzy), qu'une chaîne de formatage diffère ou qu'un fichier compilé est obsolète, et la suite de tests unitaires vérifie également les espaces réservés, les balises HTML et les espaces de bord de chaque entrée. Un message modifié nécessite donc la mise à jour de ses traductions dans la même pull request. Une nouvelle langue correspond à un nouveau fichier `<locale>.po` nommé selon la convention de nommage des paramètres régionaux de Cacti (par exemple `pt-BR`), ajouté à `CatalogTest::LOCALES` et à la vérification d'archive dans `tests/build/package.sh`. Conservez tels quels les noms de produits Cloudflare, Cacti et Thold, API et GraphQL, ainsi que les noms d'autorisations tels que `Account Cloudflare Tunnel Read`.

## Documentation

- [docs/security.md](../../security.md) : contrôles de sécurité et limitations connues
- [docs/token-permissions.md](../../token-permissions.md) : autorisations des jetons
- [docs/compatibility.md](../../compatibility.md) : versions prises en charge et interfaces utilisées
- [docs/metrics.md](../../metrics.md) : catalogue des métriques et cas où une valeur est inconnue
- [docs/collector-protocol.md](../../collector-protocol.md) : ce que `ss_cloudflare.php` lit et affiche
- [docs/schema.md](../../schema.md) : tables de base de données
- [docs/design.md](../../design.md) : choix de conception
- [CHANGELOG.md](../../../CHANGELOG.md)

## Licence

GPL-2.0-or-later. Voir [LICENSE](../../../LICENSE).

## Marques déposées

Cloudflare est une marque déposée de Cloudflare, Inc. Ce projet est un outil open source indépendant et n'est ni affilié, ni parrainé, ni approuvé par Cloudflare, Inc.

Le projet n'est pas non plus affilié au projet Cacti. Voir [docs/images/README-logo.md](../../images/README-logo.md) pour le logo.
