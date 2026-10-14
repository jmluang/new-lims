//! Direct EntrustOrderRenderer port; no network access from untrusted DTOs.
use super::*;
use serde_json::Value as V;
fn mm(n: f32) -> f32 {
    n * (72f32 / 25.4f32)
}
fn s<'a>(v: &'a V, k: &str) -> &'a str {
    v[k].as_str().unwrap_or("")
}
fn ss<'a>(v: &'a V, k: &str) -> &'a str {
    let v = s(v, k);
    if blank(v) { "" } else { v }
}
fn ar(v: &V) -> &[V] {
    v.as_array().map(Vec::as_slice).unwrap_or(&[])
}
fn label(v: &V) -> &str {
    if !blank(s(v, "label")) {
        s(v, "label")
    } else {
        s(v, "value")
    }
}
fn key(v: &V) -> Option<&str> {
    if !blank(s(v, "value")) {
        Some(s(v, "value"))
    } else {
        v["label"].as_str()
    }
}
fn checkbox(on: bool, label: &str) -> String {
    format!(
        "{}{}",
        if on { "■" } else { "□" },
        if blank(label) { "" } else { label }
    )
}
fn select(options: &V, selected: &V, fallback: bool) -> String {
    let text = ar(options)
        .iter()
        .filter(|v| !v.is_null())
        .map(|v| checkbox(key(selected).is_some() && key(selected) == key(v), label(v)))
        .collect::<String>();
    if fallback && blank(&text) {
        label(selected).into()
    } else {
        text
    }
}
fn multi(options: &V, selected: &V) -> String {
    if ar(options).is_empty() {
        ar(selected)
            .iter()
            .map(label)
            .filter(|v| !blank(v))
            .collect::<Vec<_>>()
            .join("、")
    } else {
        ar(options)
            .iter()
            .filter(|v| !v.is_null())
            .map(|v| {
                checkbox(
                    key(v).is_some_and(|k| {
                        !blank(k) && ar(selected).iter().any(|s| key(s) == Some(k))
                    }),
                    label(v),
                )
            })
            .collect()
    }
}
fn date(v: &str) -> String {
    let parts = v.split('-').collect::<Vec<_>>();
    if parts.len() == 3
        && parts[0].len() == 4
        && parts[1].len() == 2
        && parts[2].len() == 2
        && let (Ok(y), Ok(m), Ok(d)) = (
            parts[0].parse::<u32>(),
            parts[1].parse::<u32>(),
            parts[2].parse::<u32>(),
        )
    {
        let days = match m {
            1 | 3 | 5 | 7 | 8 | 10 | 12 => 31,
            4 | 6 | 9 | 11 => 30,
            2 => {
                if y % 4 == 0 && (y % 100 != 0 || y % 400 == 0) {
                    29
                } else {
                    28
                }
            }
            _ => 0,
        };
        if d > 0 && d <= days {
            return format!("{y:04}.{m}.{d}");
        }
    }
    v.into()
}
fn sanitize(v: &str) -> String {
    v.chars()
        .filter_map(|c| {
            if c == '\t' {
                Some(' ')
            } else if java_category_c(c) {
                None
            } else {
                Some(c)
            }
        })
        .collect::<String>()
        .trim_matches(|c: char| c <= '\u{0020}')
        .into()
}
fn java_category_c(c: char) -> bool {
    super::java_unicode_c::contains(c)
}
struct E {
    r: Renderer,
    y: f32,
    m: f32,
    w: f32,
    rh: f32,
    pad: f32,
}
impl E {
    fn new(font: Arc<FontData>, heading: Option<Arc<FontData>>) -> Self {
        let m = mm(15.);
        let mut r = Renderer::new(font, heading);
        r.new_page();
        Self {
            r,
            y: HEIGHT - m,
            m,
            w: WIDTH - m * 2.,
            rh: mm(6.5),
            pad: mm(1.5),
        }
    }
    fn ensure(&mut self, h: f32) {
        if self.y - h < self.m {
            self.r.new_page();
            self.y = HEIGHT - self.m;
        }
    }
    fn text(&mut self, text: &str, size: f32, x: f32, y: f32) -> Result<()> {
        let hex = self.r.font.hex(text)?;
        self.r.op(format!(
            "q 0 0 0 rg BT /F1 {} Tf {} {} Td <{hex}> Tj ET Q\n",
            num(size),
            num(x),
            num(y)
        ));
        Ok(())
    }
    fn rect(&mut self, x: f32, y: f32, w: f32, h: f32) {
        self.r.op(format!(
            "q 0 0 0 RG 0.5 w {} {} {} {} re S Q\n",
            num(x),
            num(y),
            num(w),
            num(h)
        ));
    }
    fn fill(&mut self, x: f32, y: f32, w: f32, h: f32) {
        self.r.op(format!(
            "q 0.9 0.9 0.9 rg {} {} {} {} re f Q\n",
            num(x),
            num(y),
            num(w),
            num(h)
        ));
    }
    fn line(&mut self, x: f32, y: f32, a: f32, b: f32) {
        self.r.op(format!(
            "q 0 0 0 RG 0.5 w {} {} m {} {} l S Q\n",
            num(x),
            num(y),
            num(a),
            num(b)
        ));
    }
    fn truncate(&self, t: &str, size: f32, max: f32) -> Result<String> {
        let mut t = sanitize(t);
        if self.r.font.measure(&t, size)? <= max {
            return Ok(t);
        }
        while !t.is_empty() && self.r.font.measure(&format!("{t}..."), size)? > max {
            t.pop();
        }
        Ok(t + "...")
    }
    fn centered(&mut self, t: &str, size: f32, x: f32, y: f32, w: f32, h: f32) -> Result<()> {
        if t.is_empty() {
            return Ok(());
        }
        let mut t = sanitize(t);
        let max = w - self.pad * 2.;
        let mut tw = self.r.font.measure(&t, size)?;
        if tw > max {
            t = self.truncate(&t, size, max)?;
            tw = self.r.font.measure(&t, size)?;
        }
        self.text(
            &t,
            size,
            (x + (w - tw) / 2.).max(x + self.pad),
            y + (h - size) / 2.,
        )
    }
    fn cell(&mut self, t: &str, x: f32, y: f32, w: f32, h: f32, kind: u8) -> Result<()> {
        if kind == 1 || kind == 2 || kind == 4 {
            self.fill(x, y, w, h);
        }
        self.rect(x, y, w, h);
        if t.is_empty() {
            return Ok(());
        }
        if kind == 2 {
            return self.centered(t, 9., x, y, w, h);
        }
        let t = sanitize(t);
        if kind == 3 {
            return self.text(
                &t,
                9.,
                x + (w - self.r.font.measure(&t, 9.)?) / 2.,
                y + (h - 9.) / 2.,
            );
        }
        if kind == 4 && t.ends_with('*') {
            let main = &t[..t.len() - 1];
            self.text(main, 9., x + self.pad, y + h / 2. - 9. / 2.)?;
            return self.text(
                "*",
                9.,
                x + self.pad + self.r.font.measure(main, 9.)?,
                y + h / 2. - 9. / 2.,
            );
        }
        let t = self.truncate(&t, 9., w - self.pad * 2.)?;
        self.text(&t, 9., x + self.pad, y + h / 2. - 9. / 2.)
    }
    fn row(&mut self, texts: &[&str], widths: &[f32], kinds: &[u8]) -> Result<()> {
        let mut x = self.m;
        for i in 0..texts.len() {
            self.cell(texts[i], x, self.y - self.rh, widths[i], self.rh, kinds[i])?;
            x += widths[i];
        }
        self.y -= self.rh;
        Ok(())
    }
    fn section(&mut self, t: &str) -> Result<()> {
        self.fill(self.m, self.y - self.rh, self.w, self.rh);
        self.rect(self.m, self.y - self.rh, self.w, self.rh);
        self.text(
            &sanitize(t),
            10.,
            self.m + self.pad,
            self.y - self.rh + self.pad,
        )?;
        self.y -= self.rh;
        Ok(())
    }
}
pub(super) fn render(
    p: V,
    font: Arc<FontData>,
    heading: Option<Arc<FontData>>,
    output: &Path,
) -> Result<usize> {
    ensure!(p.is_object(), "Entrust payload must be an object");
    let mut a = E::new(font, heading);
    let b = &p["base"];
    let l = &p["logistics"];
    let req = &p["requirements"];
    let sig = &p["signatures"];
    let hh = a.rh * 2.;
    let by = a.y - hh;
    a.rect(a.m, by, a.w, hh);
    let fw = mm(40.);
    a.centered(ss(l, "laboratory_name"), 14., a.m, by, a.w - fw, hh)?;
    let fx = a.m + a.w - fw;
    a.line(fx, a.y, fx, by);
    a.line(fx, a.y - hh / 2., a.m + a.w, a.y - hh / 2.);
    a.centered("表单编号：FO-12-01", 9., fx, a.y - hh / 2., fw, hh / 2.)?;
    a.centered("版本：v1.1", 9., fx, by, fw, hh / 2.)?;
    a.y = by - mm(3.);
    a.text(
        "实验委托单",
        16.,
        a.m + (a.w - a.r.font.measure("实验委托单", 16.)?) / 2.,
        a.y - mm(8.),
    )?;
    a.y -= mm(12.);
    a.ensure(a.rh * 3. + mm(2.));
    a.rect(a.m, a.y - a.rh * 3., a.w, a.rh * 3.);
    let bw = [a.w * 0.15, a.w * 0.35, a.w * 0.23, a.w * 0.27];
    let mut urgency = select(&b["urgency_options"], &b["urgency"], false);
    if urgency.is_empty() {
        urgency = "□常规□加急（加收50%费用）".into();
    }
    a.row(
        &[
            "委托日期",
            &date(s(b, "entrust_date")),
            "紧急程度",
            &urgency,
        ],
        &bw,
        &[1, 0, 1, 0],
    )?;
    a.row(
        &["计划结束时间", &date(s(b, "planned_end_date")), "", ""],
        &bw,
        &[1, 0, 0, 0],
    )?;
    a.row(
        &[
            "委托编号",
            s(b, "entrust_number"),
            "合同编号",
            s(b, "contract_number"),
        ],
        &bw,
        &[1, 0, 1, 0],
    )?;
    a.y -= mm(2.);
    a.ensure(a.rh * 7. + mm(2.));
    a.section("委托单位信息")?;
    for (name, required, party) in [
        ("委托单位", true, &p["client"]),
        ("制造商", true, &p["manufacturer"]),
        ("生产厂", false, &p["producer"]),
    ] {
        let lw = a.w * 0.15;
        let cw = a.w * 0.4;
        let fl = a.w * 0.07;
        let remain = a.w - lw - cw - fl * 2.;
        let mut fv = (remain / 2.).max(mm(15.));
        let adj = a.w - lw - cw - fl * 2. - fv * 2.;
        if adj.abs() > 0.01 {
            fv += adj / 2.;
        }
        let lab = if required {
            format!("{name}*")
        } else {
            name.into()
        };
        let k = if required { 4 } else { 1 };
        let company = if party["company_name"].is_null() {
            s(party, "name")
        } else {
            s(party, "company_name")
        };
        a.row(
            &[
                &lab,
                company,
                "联系人",
                s(party, "contact"),
                "电话",
                s(party, "phone"),
            ],
            &[lw, cw, fl, fv, fl, fv],
            &[k, 0, 0, 0, 0, 0],
        )?;
        let aw = cw + fv;
        let ew = a.w - lw - aw - fl;
        a.row(
            &[
                if required { "地址*" } else { "地址" },
                s(party, "address"),
                "邮箱",
                s(party, "email"),
            ],
            &[lw, aw, fl, ew],
            &[k, 0, 0, 0],
        )?;
        a.y -= mm(1.);
    }
    a.y -= mm(2.);
    let standards = ar(&req["standards"]);
    let ns = standards.len().max(1);
    a.ensure(a.rh * (5 + ns) as f32 + mm(2.));
    a.section("检测要求以及报告要求")?;
    let sw = [a.w * 0.60, a.w * 0.20, a.w * 0.20];
    a.row(&["标准号及版本", "资质要求", "报告语言"], &sw, &[2, 2, 2])?;
    if standards.is_empty() {
        a.row(&["", "", ""], &sw, &[0, 3, 3])?;
    } else {
        for st in standards {
            ensure!(!st.is_null(), "Null standard");
            let code = s(st, "standard_code");
            let notes = s(st, "notes");
            let text = if blank(notes) {
                code.into()
            } else if blank(code) {
                notes.into()
            } else {
                format!("{code} {notes}")
            };
            a.row(
                &[
                    &text,
                    s(st, "qualification_requirement"),
                    s(st, "report_language"),
                ],
                &sw,
                &[0, 3, 3],
            )?;
        }
    }
    let lw = a.w * 0.15;
    let reportw = a.w * 0.65;
    let half = a.w * 0.5;
    a.row(
        &[
            "报告形式",
            &multi(&req["report_form_options"], &req["report_forms"]),
            "样品是否返还",
            &select(&req["sample_return_options"], &req["sample_return"], true),
        ],
        &[lw, reportw - lw, lw, a.w - reportw - lw],
        &[0, 0, 0, 0],
    )?;
    a.row(
        &[
            "报告提交",
            &select(
                &req["report_submission_options"],
                &req["report_submission"],
                true,
            ),
            "准许检测分包",
            &select(
                &req["allow_subcontract_options"],
                &req["allow_subcontract"],
                true,
            ),
        ],
        &[lw, half - lw, lw, half - lw],
        &[0, 0, 0, 0],
    )?;
    a.row(&["备注", s(req, "remarks")], &[lw, a.w - lw], &[0, 0])?;
    a.y -= mm(2.);
    a.ensure(a.rh * 5.);
    a.section("*样品信息")?;
    let empty = V::Null;
    let samples: Vec<&V> = if !ar(&p["samples"]).is_empty() {
        ar(&p["samples"]).iter().collect()
    } else if !p["sample"].is_null() {
        vec![&p["sample"]]
    } else {
        vec![&empty]
    };
    for sample in samples {
        a.ensure(a.rh * 4.);
        let c1 = a.w * 0.13;
        let c2 = a.w * 0.23;
        let c3 = a.w * 0.12;
        let c4 = a.w * 0.12;
        let c5 = a.w * 0.15;
        let c6 = a.w - (c1 + c2 + c3 + c4 + c5);
        let ws = [c1, c2, c3, c4, c5, c6];
        let condition = &sample["condition"];
        let ck = key(condition).unwrap_or("");
        let cl = label(condition);
        let good = ck.eq_ignore_ascii_case("good") || ck.contains('好') || cl.contains('好');
        let abnormal =
            ck.eq_ignore_ascii_case("abnormal") || ck.contains("异常") || cl.contains("异常");
        let note = ss(sample, "condition_note");
        let al = if note.is_empty() {
            "异常（____）".into()
        } else {
            format!("异常（{note}）")
        };
        let status = format!("{} {}", checkbox(good, "完好"), checkbox(abnormal, &al));
        let q = sample["quantity"]
            .as_i64()
            .map(|n| n.to_string())
            .unwrap_or_default();
        let unit = ss(sample, "quantity_unit");
        let qty = if q.is_empty() {
            unit.into()
        } else if unit.is_empty() {
            q
        } else {
            format!("{q} {unit}")
        };
        a.row(
            &[
                "名称*",
                ss(sample, "name"),
                "额定电流",
                ss(sample, "current"),
                "状态",
                &status,
            ],
            &ws,
            &[4, 0, 1, 0, 1, 0],
        )?;
        a.row(
            &[
                "型号*",
                ss(sample, "model"),
                "额定功率*",
                ss(sample, "power"),
                "样品数量",
                &qty,
            ],
            &ws,
            &[4, 0, 4, 0, 1, 0],
        )?;
        a.row(
            &[
                "额定电压*",
                ss(sample, "voltage"),
                "额定频率",
                ss(sample, "frequency"),
                "",
                "",
            ],
            &ws,
            &[4, 0, 1, 0, 0, 0],
        )?;
        a.row(&["备注", ss(sample, "remarks")], &[c1, a.w - c1], &[1, 0])?;
    }
    a.y -= mm(2.);
    a.ensure(a.rh * 6. + mm(2.));
    a.section("*样品寄送地址")?;
    let lw = a.w * 0.15;
    let iw = a.w * 0.60;
    let rw = a.w - (lw + iw);
    let rx = a.m + lw + iw;
    a.cell("实验室名称", a.m, a.y - a.rh, lw, a.rh, 1)?;
    a.cell(ss(l, "laboratory_name"), a.m + lw, a.y - a.rh, iw, a.rh, 0)?;
    a.fill(rx, a.y - a.rh, rw, a.rh);
    a.rect(rx, a.y - a.rh, rw, a.rh);
    a.text("特别说明", 9., rx + a.pad, a.y - a.rh + a.pad)?;
    a.y -= a.rh;
    a.rect(rx, a.y - a.rh * 4., rw, a.rh * 4.);
    let remarks = ss(l, "shipping_notes");
    if !remarks.is_empty() {
        let v = a.truncate(remarks, 9., rw - a.pad * 2.)?;
        a.text(&v, 9., rx + a.pad, a.y - 9. - a.pad)?;
    }
    a.fill(a.m, a.y - a.rh * 2., lw, a.rh * 2.);
    a.rect(a.m, a.y - a.rh * 2., lw, a.rh * 2.);
    a.rect(a.m + lw, a.y - a.rh * 2., iw, a.rh * 2.);
    a.text(
        "实验室地址",
        9.,
        a.m + a.pad,
        (a.y - a.rh * 2.) + ((a.rh * 2. - 9.) / 2.),
    )?;
    a.text(
        &sanitize(ss(l, "laboratory_address")),
        9.,
        a.m + lw + a.pad,
        a.y - 9. - a.pad,
    )?;
    a.y -= a.rh * 2.;
    a.row(&["联系人", ss(l, "laboratory_contact")], &[lw, iw], &[1, 0])?;
    a.row(&["联系电话", ss(l, "laboratory_phone")], &[lw, iw], &[1, 0])?;
    a.y -= mm(2.);
    let dh = mm(20.5);
    a.ensure(dh + a.rh * 3. + mm(4.));
    let db = a.y - dh;
    a.rect(a.m, db, a.w, dh);
    let baseline = db + dh / 2. - 10. / 2.;
    a.text(
        "委托单位声明：上述提供资料正确无误！",
        16.,
        a.m + a.pad + mm(5.),
        baseline,
    )?;
    a.text("委托人（客户）签字", 10., a.m + a.w * 0.6, baseline)?;
    let sd = date(s(sig, "client_signed_at"));
    if !blank(&sd) {
        a.text(&format!("日期 {sd}"), 10., a.m + a.w * 0.6, db + mm(4.))?;
    }
    let signature = s(sig, "client_signature_name");
    if !blank(signature) {
        if signature.starts_with("http://") || signature.starts_with("https://") {
            if let Ok(bytes) = load_http_asset(signature)
                && let Ok(im) = lims_images::decode(&bytes)
            {
                let x = a.m + a.w * 0.8;
                let y = db + mm(2.);
                let width = a.w * 0.2;
                let height = dh - mm(4.);
                let aspect = im.width() as f32 / im.height() as f32;
                let aa = width / height;
                let (dw, dh) = if aspect > aa {
                    (width, width / aspect)
                } else {
                    (height * aspect, height)
                };
                let _ = a.r.image_at(
                    &bytes,
                    x + (width - dw) / 2.,
                    y + (height - dh) / 2.,
                    dw,
                    dh,
                );
            }
        } else {
            a.centered(
                signature,
                10.,
                a.m + a.w * 0.8,
                db + mm(2.),
                a.w * 0.2,
                dh - mm(4.),
            )?;
        }
    }
    a.y = db - mm(2.);
    let cw = a.w / 5.;
    let rh = a.rh * 1.5;
    for (lab, department, name, datekey) in [
        (
            "实验室资源满足*",
            "综合部确认",
            "lab_resource_confirmed_by",
            "lab_resource_confirmed_at",
        ),
        (
            "客户要求的评审*",
            "检测部确认",
            "lab_reviewed_by",
            "lab_reviewed_at",
        ),
    ] {
        let ds = date(s(sig, datekey));
        for (i, (v, k)) in [
            (lab, 2),
            (department, 2),
            (s(sig, name), 3),
            ("日期", 2),
            (&ds, 3),
        ]
        .iter()
        .enumerate()
        {
            a.cell(v, a.m + cw * i as f32, a.y - rh, cw, rh, *k)?;
        }
        a.y -= rh;
    }
    a.r.finish_generic("", None, "", vec![], output)
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn java_sanitizer_removes_all_category_c_without_losing_real_characters() {
        assert_eq!(
            sanitize(" \t甲\u{000a}乙\u{200b}\u{e000}\u{0378}\u{20087} "),
            "甲乙\u{20087}"
        );
        assert_eq!(sanitize("\u{00a0}X\u{00a0}"), "\u{00a0}X\u{00a0}");
    }
    #[test]
    fn date_and_selection_match_renderer() {
        assert_eq!(date("2024-02-29"), "2024.2.29");
        assert_eq!(date("2025-02-29"), "2025-02-29");
        let p = serde_json::json!({"options":[{"value":"a","label":"甲"},{"value":"b","label":"乙"}],"selected":{"value":"b"}});
        assert_eq!(select(&p["options"], &p["selected"], false), "□甲■乙");
    }
}
