<?php
// paperactions.php -- HotCRP helpers for common paper actions
// HotCRP is Copyright (c) 2008-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperActions {

    static function setDecision($prow) {
        global $Conf, $Me, $Error, $OK;
        $ajax = defval($_REQUEST, "ajax", false);
        if ($Me->can_set_decision($prow)) {
            $dnum = cvtint(@$_REQUEST["decision"]);
            $decs = $Conf->decision_map();
            if (isset($decs[$dnum])) {
                $result = $Conf->qe("update Paper set outcome=$dnum where paperId=$prow->paperId");
                if ($result && $ajax)
                    $Conf->confirmMsg("Saved");
                else if ($result)
                    $Conf->confirmMsg("Decision for paper #$prow->paperId set to " . htmlspecialchars($decs[$dnum]) . ".");
                if ($dnum > 0 || $prow->outcome > 0)
                    $Conf->updatePaperaccSetting($dnum > 0);
            } else {
                $Conf->errorMsg("Bad decision value.");
                $Error["decision"] = true;
            }
        } else
            $Conf->errorMsg("You can’t set the decision for paper #$prow->paperId." . ($Me->allow_administer($prow) ? "  (<a href=\"" . selfHref(array("forceShow" => 1)) . "\">Override conflict</a>)" : ""));
        if ($ajax)
            $Conf->ajaxExit(array("ok" => $OK && !defval($Error, "decision")));
    }

    static function save_review_preferences($prefarray) {
        global $Conf;
        $q = array();
        if ($Conf->sversion >= 69) {
            foreach ($prefarray as $p)
                $q[] = "($p[0],$p[1],$p[2]," . ($p[3] === null ? "NULL" : $p[3]) . ")";
            if (count($q))
                return $Conf->qe("insert into PaperReviewPreference (paperId,contactId,preference,expertise) values " . join(",", $q) . " on duplicate key update preference=values(preference), expertise=values(expertise)");
        } else {
            foreach ($prefarray as $p)
                $q[] = "($p[0],$p[1],$p[2])";
            if (count($q))
                return $Conf->qe("insert into PaperReviewPreference (paperId,contactId,preference) values " . join(",", $q) . " on duplicate key update preference=values(preference)");
        }
        return true;
    }

    static function setReviewPreference($prow) {
        global $Conf, $Me, $Error, $OK;
        $ajax = defval($_REQUEST, "ajax", false);
        if (!$Me->allow_administer($prow)
            || ($contactId = cvtint(@$_REQUEST["reviewer"])) <= 0)
            $contactId = $Me->contactId;
        if (isset($_REQUEST["revpref"]) && ($v = parse_preference($_REQUEST["revpref"]))) {
            if (self::save_review_preferences(array(array($prow->paperId, $contactId, $v[0], $v[1]))))
                $Conf->confirmMsg($ajax ? "Saved" : "Review preference saved.");
            else
                $Error["revpref"] = true;
            $v = unparse_preference($v);
        } else {
            $v = null;
            $Conf->errorMsg($ajax ? "Bad preference" : "Bad preference “" . htmlspecialchars($_REQUEST["revpref"]) . "”.");
            $Error["revpref"] = true;
        }
        if ($ajax)
            $Conf->ajaxExit(array("ok" => $OK && !@$Error["revpref"],
                                  "value" => $v));
    }

    static function rankContext($prow) {
        global $Conf, $Me, $Error, $OK;
        $ajax = defval($_REQUEST, "ajax", false);
        $tag = $Conf->setting_data("tag_rank", "");
        if (!$tag || !$Me->can_change_tag($prow, "~$tag", null, 1)) {
            $Conf->errorMsg("You don’t have permission to rank this paper.");
            $Error["rank"] = true;
        } else {
            $result = $Conf->qe("select Paper.paperId, title, tagIndex from Paper join PaperTag on (PaperTag.paperId=Paper.paperId and PaperTag.tag='" . sqlq($Me->contactId . "~" . $tag) . "') order by tagIndex, Paper.paperId");
            $x = array();
            $prowIndex = -1;
            while (($row = edb_row($result))) {
                $t = "$row[2]. <a class='q' href='" . hoturl("paper", "p=$row[0]") . "'>#$row[0] " . htmlspecialchars(titleWords($row[1], 48)) . "</a>";
                if ($row[0] == $prow->paperId) {
                    $prowIndex = count($x);
                    $t = "<div class='rankctx_h'>" . $t . "</div>";
                } else
                    $t = "<div class='rankctx'>" . $t . "</div>";
                $x[] = $t;
            }
            $first = max(0, min($prowIndex - 3, count($x) - 7));
            $x = array_slice($x, $first, min(7, count($x) - $first));
            $Conf->confirmMsg(join("", $x));
        }
        if ($ajax)
            $Conf->ajaxExit(array("ok" => $OK && !defval($Error, "rank")), true);
    }

    static function set_follow($prow) {
        global $Conf, $Me, $OK;
        $ajax = defval($_REQUEST, "ajax", 0);
        $cid = $Me->contactId;
        if ($Me->privChair && ($x = cvtint(@$_REQUEST["contactId"])) > 0)
            $cid = $x;
        saveWatchPreference($prow->paperId, $cid, WATCHTYPE_COMMENT, defval($_REQUEST, "follow"));
        if ($OK)
            $Conf->confirmMsg("Saved");
        if ($ajax)
            $Conf->ajaxExit(array("ok" => $OK));
    }

    private static function set_paper_pc($prow, $value, $contact, $ajax, $type) {
        global $Conf, $Error, $OK;

        // canonicalize $value
        if ($value === "0" || $value === 0 || $value === "none")
            $pc = 0;
        else if (is_string($value))
            $pc = pcByEmail($value);
        else if (is_object($value) && ($value instanceof Contact))
            $pc = $value;
        else
            $pc = null;

        if ($type == "manager" ? !$contact->privChair : !$contact->can_administer($prow)) {
            $Conf->errorMsg("You don’t have permission to set the $type.");
            $Error[$type] = true;
        } else if ($pc === 0
                   || ($pc && $pc->isPC && $pc->can_accept_review_assignment($prow))) {
            $contact->assign_paper_pc($prow, $type, $pc);
            if ($OK && $ajax)
                $Conf->confirmMsg("Saved");
        } else if ($pc) {
            $Conf->errorMsg(Text::user_html($pc) . " can’t be the $type for paper #" . $prow->paperId . ".");
            $Error[$type] = true;
        } else {
            $Conf->errorMsg("Bad $type setting “" . htmlspecialchars($value) . "”.");
            $Error[$type] = true;
        }

        if ($ajax)
            $Conf->ajaxExit(array("ok" => $OK && !@$Error[$type]));
        return $OK && !@$Error[$type];
    }

    static function set_lead($prow, $value, $contact, $ajax = false) {
        return self::set_paper_pc($prow, $value, $contact, $ajax, "lead");
    }

    static function set_shepherd($prow, $value, $contact, $ajax = false) {
        return self::set_paper_pc($prow, $value, $contact, $ajax, "shepherd");
    }

    static function set_manager($prow, $value, $contact, $ajax = false) {
        return self::set_paper_pc($prow, $value, $contact, $ajax, "manager");
    }

    static function setTags($prow) {
        global $Conf, $Me, $Error, $OK;
        if (isset($_REQUEST["cancelsettags"]))
            return;
        $ajax = @$_REQUEST["ajax"];

        // save tags using assigner
        $x = array("paper,tag");
        if (isset($_REQUEST["tags"])) {
            $x[] = "$prow->paperId,all#clear";
            foreach (TagInfo::split($_REQUEST["tags"]) as $t)
                $x[] = "$prow->paperId," . CsvGenerator::quote($t);
        }
        foreach (TagInfo::split((string) @$_REQUEST["addtags"]) as $t)
            $x[] = "$prow->paperId," . CsvGenerator::quote($t);
        foreach (TagInfo::split((string) @$_REQUEST["deltags"]) as $t)
            $x[] = "$prow->paperId," . CsvGenerator::quote($t . "#clear");
        $assigner = new AssignmentSet($Me, $Me->is_admin_force());
        $assigner->parse(join("\n", $x));
        $error = join("<br>", $assigner->errors_html());
        $ok = $assigner->execute();

        // load results
        $prow->load_tags();
        $tagger = new Tagger;
        $editable = $tagger->paper_editable($prow);
        $tags_edit_text = $tagger->unparse($editable);
        $viewable = $tagger->viewable($prow->paperTags);
        $tags_view_html = $tagger->unparse_and_link($viewable, $prow->paperTags, false, !$prow->has_conflict($Me));
        $tags_color = TagInfo::color_classes($viewable);

        // exit
        if ($ajax && $ok) {
            $treport = self::tag_report($prow);
            if ($treport->warnings)
                $Conf->warnMsg(join("<br>", $treport->warnings));
            $Conf->ajaxExit(array("ok" => true, "tags" => TagInfo::split($viewable),
                                  "tags_edit_text" => $tags_edit_text,
                                  "tags_view_html" => $tags_view_html,
                                  "tags_color" => $tags_color), true);
        } else if ($ajax)
            $Conf->ajaxExit(array("ok" => false, "error" => $error));
        else {
            if ($error)
                $_SESSION["redirect_error"] = array("paperId" => $prow->paperId, "tags" => $error);
            redirectSelf();
        }
        // NB normally redirectSelf() does not return
    }

    static function tag_report($prow) {
        global $Me;
        if (!$Me->can_view_tags($prow))
            return (object) array("ok" => false);
        $ret = (object) array("ok" => true, "warnings" => array(), "messages" => array());
        if (($vt = TagInfo::vote_tags())) {
            $myprefix = $Me->contactId . "~";
            $qv = $myvotes = array();
            foreach ($vt as $tag => $v) {
                $qv[] = $myprefix . $tag;
                $myvotes[strtolower($tag)] = 0;
            }
            $result = Dbl::qe("select tag, sum(tagIndex) from PaperTag where tag ?a group by tag", $qv);
            while (($row = edb_row($result))) {
                $lbase = strtolower(substr($row[0], strlen($myprefix)));
                $myvotes[$lbase] += +$row[1];
            }
            $vlo = $vhi = array();
            foreach ($vt as $tag => $vlim) {
                $lbase = strtolower($tag);
                if ($myvotes[$lbase] < $vlim)
                    $vlo[] = '<a class="q" href="' . hoturl("search", "q=editsort:-%23~$tag") . '">~' . $tag . '</a>#' . ($vlim - $myvotes[$lbase]);
                else if ($myvotes[$lbase] > $vlim
                         && $prow->has_tag($myprefix . $tag))
                    $vhi[] = '<span class="nw"><a class="q" href="' . hoturl("search", "q=sort:-%23~$tag+edit:%23~$tag") . '">~' . $tag . '</a> (' . ($myvotes[$lbase] - $vlim) . " over)</span>";
            }
            if (count($vlo))
                $ret->messages[] = 'Remaining <a class="q" href="' . hoturl("help", "t=votetags") . '">votes</a>: ' . join(", ", $vlo);
            if (count($vhi))
                $ret->warnings[] = 'Overallocated <a class="q" href="' . hoturl("help", "t=votetags") . '">votes</a>: ' . join(", ", $vhi);
        }
        return $ret;
    }

    static function all_tags($papersel = null) {
        global $Conf, $Me, $Error, $OK;
        if (!$Me->isPC)
            $Conf->ajaxExit(array("ok" => false));
        $ajax = defval($_REQUEST, "ajax", false);
        $q = "select distinct tag from PaperTag t";
        $where = array();
        if (!$Me->allow_administer(null)) {
            $q .= " left join PaperConflict pc on (pc.paperId=t.paperId and pc.contactId=$Me->contactId)";
            $where[] = "coalesce(pc.conflictType,0)<=0";
        }
        if ($papersel)
            $where[] = "t.paperId in (" . join(",", mkarray($papersel)) . ")";
        if (count($where))
            $q .= " where " . join(" and ", $where);
        $tags = array();
        $result = $Conf->qe($q);
        while (($row = edb_row($result))) {
            $twiddle = strpos($row[0], "~");
            if ($twiddle === false
                || ($twiddle == 0 && $row[0][1] == "~" && $Me->privChair))
                $tags[] = $row[0];
            else if ($twiddle > 0 && substr($row[0], 0, $twiddle) == $Me->contactId)
                $tags[] = substr($row[0], $twiddle);
        }
        $Conf->ajaxExit(array("ok" => true, "tags" => $tags));
    }

}
