# Rapport d'audit — pladigit

Genere le 20/07/2026 17:58:36

## Synthese

Le projet presente une qualite de code bonne (score qualite : 9/10). Les principaux points d'attention concernent la securite (43 vulnerabilite(s) critique(s) a traiter en priorite), ainsi que 28 probleme(s) important(s) d'architecture ou de performance repartis dans le projet. Les priorites sont detaillees ci-dessous.

**Score global : 92/100**

| Categorie | Score |
|---|---|
| Quality | 9/10 |
| Security | 9.9/10 |
| Performance | 9.9/10 |
| Architecture | 9.7/10 |
| Documentation | 5.2/10 |

## Statistiques

- Fichiers analyses : 732
- Taille totale : 6 Mo
- Lignes de code : 125112

## Top priorites

- [critical] install/index.php:176 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:120 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:128 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:129 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:130 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:131 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:144 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:145 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:150 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:155 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:164 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:165 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:166 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:171 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:179 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:234 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:236 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:253 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] install/runner.php:269 — Appel systeme direct ({function}) : verifier l'origine des arguments
- [critical] app/Services/BackupService.php:132 — Appel systeme direct ({function}) : verifier l'origine des arguments