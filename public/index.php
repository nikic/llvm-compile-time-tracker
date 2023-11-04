<?php

require __DIR__ . '/../src/web_common.php';
$defaultNumCommits = 1000;

$config = upgradeConfigName($_GET['config'] ?? 'Overview');
$stat = $_GET['stat'] ?? DEFAULT_METRIC;
$sortBy = $_GET['sortBy'] ?? 'date';
$filterRemote = $_GET['remote'] ?? null;
$filterBranch = $_GET['branch'] ?? null;
$startHash = $_GET['startHash'] ?? null;
$numCommits = $_GET['numCommits'] ?? $defaultNumCommits;
$minInterestingness = $_GET['minInterestingness'] ?? 0.0;

$isFrontPage = $filterRemote === null && $filterBranch === null;

printHeader();

echo "<form>\n";
echo "<label>Config: "; printConfigSelect($config); echo "</label>\n";
echo "<label>Metric: "; printStatSelect($stat); echo "</label>\n";
echo "<input type=\"submit\" value=\"Go\" />\n";
if ($sortBy !== 'date') {
    echo "<input type=\"hidden\" name=\"sortBy\" value=\"" . h($sortBy) . "\" />\n";
}
if ($filterRemote) {
    echo "<input type=\"hidden\" name=\"remote\" value=\"" . h($filterRemote) . "\" />\n";
}
if ($filterBranch) {
    echo "<input type=\"hidden\" name=\"branch\" value=\"" . h($filterBranch) . "\" />\n";
}
if ($startHash) {
    echo "<input type=\"hidden\" name=\"startHash\" value=\"" . h($startHash) . "\" />\n";
}
if ($numCommits != $defaultNumCommits) {
    echo "<input type=\"hidden\" name=\"numCommits\" value=\"" . h($numCommits) . "\" />\n";
}
if ($minInterestingness > 0.0) {
    echo "<input type=\"hidden\" name=\"minInterestingness\" value=\"" . h($minInterestingness) . "\" />\n";
}
echo "</form>\n";
echo "<hr />\n";

$commitData = getAllCommits();
$stddevs = new StdDevManager();

echo "<form action=\"compare_selected.php\">\n";
echo "<input type=\"hidden\" name=\"stat\" value=\"" . h($stat) . "\" />\n";
echo "Compare selected: <input type=\"submit\" value=\"Compare\" />\n";
echo "Or click the \"C\" to compare with previous.\n";

$remotes = groupByRemote($commitData);
$inactiveRemotes = [];
if ($isFrontPage) {
    [$remotes, $inactiveRemotes] = partitionRemotes($remotes);
    echo "<div>\n";
    echo "<h3 style=\"display: inline-block\">Remotes without recent activity:</h3>\n";
    foreach ($inactiveRemotes as $remote) {
        $params = ["config" => $config, "stat" => $stat, "remote" => $remote];
        echo "<a href=\"" . makeUrl("", $params) . "\">" . h($remote) . "</a>\n";
    }
    echo "</div>\n";
}

foreach ($remotes as $remote => $branchCommits) {
    if ($filterRemote !== null && $filterRemote !== $remote) {
        continue;
    }

    if ($filterBranch === null) {
        echo "<div>\n";
        echo "<h3 style=\"display: inline-block\">Remote " . h($remote) . ":</h3>\n";
        $params = ["config" => $config, "stat" => $stat, "remote" => $remote];
        if ($isFrontPage) {
            echo "Showing recent experiments. ";
            $url = makeUrl("", $params);
            echo "<a href=\"" . h($url) . "\">Show all</a>\n";
        } else {
            if ($sortBy == 'date') {
                $url = makeUrl("", $params + ["sortBy" => "name"]);
                echo "<a href=\"" . h($url) . "\">Sort by name</a>\n";
            } else {
                $url = makeUrl("", $params + ["sortBy" => "date"]);
                echo "<a href=\"" . h($url) . "\">Sort by date</a>\n";
            }
        }
        echo "</div>\n";
    }

    if ($sortBy === 'date') {
        $branchCommits = sortBranchesByDate($branchCommits);
    }
    foreach ($branchCommits as $branch => $commits) {
        if ($filterBranch !== null && $filterBranch !== $branch) {
            continue;
        }

        list($commits, $nextStartHash) = filterCommits($commits, $startHash, $numCommits);
        $commits = filterByInterestingness($commits, $config, $stat, $stddevs, $minInterestingness);

        $benches = $config === 'Overview'
            ? [...DEFAULT_CONFIGS, 'stage2-clang'] : BENCHES_GEOMEAN_LAST;
        $titles = ['', '', 'Commit', ...$benches];
        $rows = [];
        $lastMetrics = null;
        $lastHash = null;
        $lastConfigNum = null;
        foreach ($commits as $commit) {
            if ($commit === null) {
                $rows[] = ['', '', '...'];
                continue;
            }
            $hash = $commit['hash'];
            $summary = getSummaryForHash($hash);
            $metrics = null;
            if ($summary !== null) {
                if ($config === 'Overview') {
                    $metrics = $summary->getGeomeanStats($stat);
                    $metrics['stage2-clang'] = $summary->stage2Stats[$stat] ?? null;
                } else {
                    $metrics = $summary->getConfigStat($config, $stat);
                }
            }
            $row = [];
            if ($metrics && $lastHash) {
                $row[] = "<a href=\"compare.php?from=$lastHash&amp;to=$hash&amp;stat=" . h($stat) . "\">C</a>";
            } else {
                $row[] = '';
            }
            if ($metrics) {
                $row[] = "<input type=\"checkbox\" name=\"commits[]\" value=\"$hash\" style=\"margin: -1px -0.5em\" />";
            } else {
                $row[] = '';
            }
            $row[] = formatCommit($commit);

            if ($metrics) {
                foreach ($benches as $bench) {
                    $value = $metrics[$bench] ?? null;
                    $stddev = null;
                    if ($lastConfigNum === $summary->configNum) {
                        if ($config === 'Overview') {
                            if ($bench === 'stage2-clang') {
                                $stddev = $stddevs->getBenchStdDev(
                                    $summary->configNum, 'build', $bench, $stat);
                            } else {
                                $stddev = $stddevs->getBenchStdDev(
                                    $summary->configNum, $bench, 'geomean', $stat);
                            }
                        } else {
                            $stddev = $stddevs->getBenchStdDev(
                                $summary->configNum, $config, $bench, $stat);
                        }
                    }
                    $prevValue = $lastMetrics[$bench] ?? null;
                    $row[] = formatMetricDiff($value, $prevValue, $stat, $stddev);
                }
                $lastMetrics = $metrics;
                $lastHash = $hash;
                $lastConfigNum = $summary->configNum;
            } else if (hasBuildError($hash)) {
                $url = makeUrl("show_error.php", ["commit" => $hash]);
                $row[] = "Failed to build llvm-project or llvm-test-suite"
                       . " (<a href=\"" . h($url) . "\">Log</a>)";
            }
            $rows[] = $row;
        }

        echo "<h4>", h($branch), ":</h4>\n";
        echo "<table>\n";
        echo "<tr>\n";
        foreach ($titles as $title) {
            echo "<th>$title</th>\n";
        }
        echo "</tr>\n";
        foreach (array_reverse($rows) as $row) {
            echo "<tr>\n";
            $colSpan = 1;
            if (count($row) < count($titles)) {
                $colSpan =  count($titles) - count($row) + 1;
            }
            foreach ($row as $i => $value) {
                if ($colSpan > 1 && $i == count($row) - 1) {
                    echo "<td colspan=\"$colSpan\" style=\"text-align: left\">$value</td>\n";
                } else {
                    echo "<td>$value</td>\n";
                }
            }
            echo "</tr>\n";
        }
        echo "</table>\n";

        if ($nextStartHash) {
            $params = [
                "config" => $config,
                "stat" => $stat,
                "branch" => $branch,
                "startHash" => $nextStartHash,
            ];
            if ($numCommits != $defaultNumCommits) {
                $params['numCommits'] = $numCommits;
            }
            if ($minInterestingness > 0.0) {
                $params['minInterestingness'] = $minInterestingness;
            }
            $url = makeUrl("", $params + ["startHash" => $nextStartHash]);
            echo "<br><a href=\"" . h($url) . "\">Show next " . h($numCommits) ." commits</a>";
        }
    }
}
echo "</form>\n";
printFooter();

function formatCommit(array $commit): string {
    $hash = $commit['hash'];
    $shortHash = substr($hash, 0, 10);
    $title = h($commit['subject']);
    return "<a href=\"https://github.com/llvm/llvm-project/commit/$hash\" title=\"$title\">$shortHash</a>";
}

function printConfigSelect(string $name) {
    printSelect("config", $name, ['Overview', ...DEFAULT_CONFIGS]);
}

function groupByRemote(array $branchCommits): array {
    $remotes = [];
    foreach ($branchCommits as $branch => $commits) {
        $remote = strstr($branch, '/', true);
        $remotes[$remote][$branch] = $commits;
    }

    // Move the origin remote to the end. We display much more commits for it,
    // and don't want to obscure any of the "smaller" remotes.
    $origin = $remotes['origin'];
    unset($remotes['origin']);
    $remotes['origin'] = $origin;
    return $remotes;
}

function getNewestCommitDate(array $commits): DateTime {
    $commit = $commits[count($commits) - 1];
    return new DateTime($commit['commit_date']);
}

function sortBranchesByDate(array $branchCommits): array {
    uasort($branchCommits, function($a, $b) {
        return getNewestCommitDate($b) <=> getNewestCommitDate($a);
    });
    return $branchCommits;
}

function filterRecentBranches(array $branchCommits) {
    $now = new DateTime;
    return array_filter($branchCommits, function(array $commits) use ($now) {
        $date = getNewestCommitDate($commits);
        return $date->diff($now)->days <= 7;
    });
}

function partitionRemotes(array $remotes): array {
    $activeRemotes = [];
    $inactiveRemotes = [];
    foreach ($remotes as $remote => $branches) {
        $branches = filterRecentBranches($branches);
        if (empty($branches)) {
            $inactiveRemotes[] = $remote;
        } else {
            $activeRemotes[$remote] = $branches;
        }
    }
    return [$activeRemotes, $inactiveRemotes];
}

function findCommitIndex(array $commits, string $hash): ?int {
    foreach ($commits as $index => $commit) {
        if ($commit['hash'] === $hash) {
            return $index;
        }
    }
    return nuLL;
}

function filterCommits(array $commits, ?string $startHash, int $numCommits): array {
    // Note: Commits are ordered from oldest to newest
    if ($startHash !== null) {
        $startIndex = findCommitIndex($commits, $startHash);
    } else {
        $startIndex = count($commits) - 1;
    }

    if ($startIndex < $numCommits) {
        return [$commits, null];
    }

    $endIndex = $startIndex - $numCommits + 1;
    $filteredCommits = array_slice($commits, $endIndex, $numCommits);
    $nextStartHash = $commits[$endIndex - 1]['hash'] ?? null;
    return [$filteredCommits, $nextStartHash];
}

function filterByInterestingness(
    array $commits, string $config, string $stat, StdDevManager $stddevs,
    float $minInterestingness
): array {
    if ($minInterestingness <= 0.0) {
        return $commits;
    }

    $newCommits = [];
    $skippedCommits = [];
    $lastGeomean = null;
    foreach ($commits as $commit) {
        $hash = $commit['hash'];
        $summary = getSummaryForHash($hash);
        if ($summary === null) {
            continue;
        }
        $data = $summary->getConfig($config);
        if ($data === null) {
            continue;
        }
        $geomean = $data['geomean'][$stat];
        if ($lastGeomean !== null) {
            $stddev = $stddevs->getBenchStdDev($summary->configNum, $config, 'geomean', $stat);
            $diff = $geomean - $lastGeomean;
            $interestingness = getInterestingness($diff, $stddev);
            if ($diff !== 0.0 && $interestingness > $minInterestingness) {
                $numSkippedCommits = count($skippedCommits);
                if ($numSkippedCommits != 0) {
                    if ($numSkippedCommits > 1) {
                        // Indicate that some commits were omitted.
                        $newCommits[] = null;
                    }
                    $newCommits[] = $skippedCommits[$numSkippedCommits - 1];
                }
                $skippedCommits = [];
                $newCommits[] = $commit;
                $lastGeomean = $geomean;
                continue;
            }
        }
        $skippedCommits[] = $commit;
        $lastGeomean = $geomean;
    }
    return $newCommits;
}
