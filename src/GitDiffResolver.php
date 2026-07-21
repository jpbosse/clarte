<?php

namespace Clarte;

/**
 * Résout la liste des fichiers modifiés via Git, pour permettre une
 * analyse ciblée (--diff) plutôt que sur l'ensemble du projet.
 *
 * Deux modes :
 *  - Sans référence : fichiers modifiés (indexes ou non) par rapport a
 *    HEAD, plus les nouveaux fichiers non suivis. Cas d'usage : "qu'est-ce
 *    que je viens de changer avant de committer".
 *  - Avec référence (ex: 'main', 'origin/main') : fichiers qui diffèrent
 *    entre le point de divergence avec cette référence et HEAD
 *    (équivalent a `git diff --name-only base...HEAD`). Cas d'usage :
 *    analyser uniquement les changements d'une branche/PR en CI.
 *
 * Retourne null si le projet n'est pas un depot Git ou si Git échoué
 * (non installé, HEAD inexistant sur un repo vide, référence invalide...),
 * auquel cas l'appelant doit se replier sur une analyse complète plutôt
 * que de conclure à tort "aucun fichier modifié".
 */
class GitDiffResolver
{
    /**
     * @return list<string>|null Chemins relatifs (POSIX) des fichiers modifiés, ou null si indéterminable.
     */
    public function changedFiles(string $projectPath, ?string $baseRef = null): ?array
    {
        if (!$this->isGitRepo($projectPath)) {
            return null;
        }

        if ($baseRef !== null) {
            $files = $this->runGit($projectPath, ['diff', '--name-only', $baseRef . '...HEAD']);
            return $files === null ? null : array_values(array_unique(array_filter($files)));
        }

        $tracked = $this->runGit($projectPath, ['diff', '--name-only', 'HEAD']);
        $untracked = $this->runGit($projectPath, ['ls-files', '--others', '--exclude-standard']);

        if ($tracked === null || $untracked === null) {
            return null;
        }

        return array_values(array_unique(array_filter(array_merge($tracked, $untracked))));
    }

    private function isGitRepo(string $projectPath): bool
    {
        $result = $this->runGit($projectPath, ['rev-parse', '--is-inside-work-tree']);
        return $result !== null && in_array('true', $result, true);
    }

    /**
     * @param list<string> $args
     * @return list<string>|null
     */
    private function runGit(string $projectPath, array $args): ?array
    {
        if (!is_dir($projectPath)) {
            return null;
        }

        $cmd = 'git -C ' . escapeshellarg($projectPath);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $cmd .= ' 2>/dev/null';

        exec($cmd, $output, $returnCode);
        if ($returnCode !== 0) {
            return null;
        }

        return array_values(array_filter(array_map('trim', $output), fn($line) => $line !== ''));
    }
}
