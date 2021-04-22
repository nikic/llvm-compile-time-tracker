<?php

require __DIR__ . '/../src/web_common.php';
$commitsFile = DATA_DIR . '/commits.json';

$config = upgradeConfigName($_GET['config'] ?? 'NewPM-O3');
$stat = $_GET['stat'] ?? 'instructions';
$sortBy = $_GET['sortBy'] ?? 'date';
$filterRemote = $_GET['remote'] ?? null;
$filterBranch = $_GET['branch'] ?? null;
$startHash = $_GET['startHash'] ?? null;
$numCommits = $_GET['numCommits'] ?? 1000;

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
echo "</form>\n";
echo "<hr />\n";

$commitData = json_decode(file_get_contents($commitsFile), true);
$stddevs = new StdDevManager();

echo "<form action=\"compare_selected.php\">\n";
echo "<input type=\"hidden\" name=\"stat\" value=\"" . h($stat) . "\" />\n";
echo "Compare selected: <input type=\"submit\" value=\"Compare\" />\n";
echo "Or click the \"C\" to compare with previous.\n";
foreach (groupByRemote($commitData) as $remote => $branchCommits) {
    if ($filterRemote !== null && $filterRemote !== $remote) {
        continue;
    }

    if ($filterBranch === null) {
        echo "<div>\n";
        echo "<h3 style=\"display: inline-block\">Remote " . h($remote) . ":</h3>\n";
        $params = ["config" => $config, "stat" => $stat, "remote" => $remote];
        if ($isFrontPage) {
            echo "Showing recent experiments. ";
            $branchCommits = filterRecentBranches($branchCommits);
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

        $titles = array_merge(['', '', 'Commit', ...BENCHES_GEOMEAN_LAST]);
        $rows = [];
        $lastMetrics = null;
        $lastHash = null;
        $lastConfigNum = null;
        foreach ($commits as $commit) {
            $hash = $commit['hash'];
            $summary = getSummaryForHash($hash);
            $metrics = $summary !== null ? $summary->getConfigStat($config, $stat) : null;
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
                foreach (BENCHES_GEOMEAN_LAST as $bench) {
                    $value = $metrics[$bench];
                    $stddev = $lastConfigNum === $summary->configNum
                        ? $stddevs->getBenchStdDev($summary->configNum, $config, $bench, $stat)
                        : null;
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
            $rows[$hash] = $row;
        }

        echo "<h4>", h($branch), ":</h4>\n";
        echo "<table>\n";
        echo "<tr>\n";
        foreach ($titles as $title) {
            echo "<th>$title</th>\n";
        }
        echo "</tr>\n";
        foreach (array_reverse($rows) as $hash => $row) {
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
    echo "<select name=\"config\">\n";
    foreach (CONFIGS as $config) {
        $selected = $name === $config ? " selected" : "";
        echo "<option$selected>$config</option>\n";
    }
    echo "</select>\n";
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

    $endIndex = $startIndex - $numCommits + 1;
    $filteredCommits = array_slice($commits, $endIndex, $numCommits);
    $nextStartHash = $commits[$endIndex - 1]['hash'] ?? null;
    return [$filteredCommits, $nextStartHash];
}
