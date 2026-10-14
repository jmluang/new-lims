//! Native Contract renderer. Source: unchanged Java ContractPdfRenderer and renderer/*.
//! Preserves original layout defects as diagnostics; never invokes Java or rasterizes pages.
use super::*;
use serde_json::{Value as V, json};
fn s<'a>(p: &'a V, k: &str) -> &'a str {
    p[k].as_str().unwrap_or("")
}
fn a<'a>(p: &'a V, k: &str) -> &'a [V] {
    p[k].as_array().map(Vec::as_slice).unwrap_or(&[])
}
fn n(p: &V, k: &str) -> i64 {
    p[k].as_i64().unwrap_or(0)
}
fn tr(s: &str) -> &str {
    s.trim_matches(|c: char| c as u32 <= 32)
}
fn nb<'a>(s: &'a str, d: &'a str) -> &'a str {
    if blank(s) { d } else { s }
}
fn mm(n: f64) -> f32 {
    (n * 72. / 25.4) as f32
}
fn bt(r: &mut Renderer, s: &str, size: f32, x: f32, y: f32) -> Result<()> {
    let hex = r.font.hex(s)?;
    r.op(format!(
        "0 0 0 rg BT /F1 {} Tf {} {} Td <{hex}> Tj ET\n",
        num(size),
        num(x),
        num(y)
    ));
    Ok(())
}
fn bc(r: &mut Renderer, s: &str, size: f32, x: f32, y: f32) -> Result<()> {
    let x = x - r.font.measure(s, size)? / 2.;
    bt(r, s, size, x, y)
}
fn line(r: &mut Renderer, x: f32, y: f32, x2: f32, y2: f32) {
    r.op(format!(
        "q 0.5 w {} {} m {} {} l S Q\n",
        num(x),
        num(y),
        num(x2),
        num(y2)
    ))
}
fn rect(r: &mut Renderer, x: f32, y: f32, w: f32, h: f32) {
    r.op(format!(
        "q 0.5 w {} {} {} {} re S Q\n",
        num(x),
        num(y),
        num(w),
        num(h)
    ))
}
fn cwrap(r: &Renderer, s: &str, size: f32, w: f32) -> Result<Vec<String>> {
    if blank(s) {
        return Ok(vec![String::new()]);
    }
    let mut out = vec![];
    let mut cur = String::new();
    for c in s.chars() {
        ensure!(
            c as u32 <= 0xffff,
            "Contract Java UTF-16 char wrapping rejects surrogate for U+{:X}",
            c as u32
        );
        cur.push(c);
        if r.font.measure(&cur, size)? > w {
            if cur.chars().count() > 1 {
                cur.pop();
                out.push(cur);
                cur = c.to_string();
            } else {
                out.push(cur);
                cur = String::new();
            }
        }
    }
    if !cur.is_empty() {
        out.push(cur);
    }
    Ok(out)
}
fn sample() -> Result<V> {
    // Java LocalDate.now() uses the runtime's local timezone, not UTC days.
    let today = chrono::Local::now().date_naive();
    let plus_two = today
        .checked_add_days(chrono::Days::new(2))
        .context("Date outside supported range")?;
    Ok(serde_json::from_str(
        &include_str!("contract-defaults.json")
            .replace("@TODAY@", &today.to_string())
            .replace("@PLUS2@", &plus_two.to_string()),
    )?)
}
fn defaults(p: &mut V) {
    for (k, v) in [
        ("headerCompanyName", "中山市鑫普达检测有限公司"),
        ("headerReportNumber", ""),
        ("headerBadgeLabel", "公众号"),
        ("title", "检测报告"),
        ("reportNumber", ""),
        ("productName", ""),
        ("modelSpecification", ""),
        ("entrustCompany", ""),
        ("testItems", ""),
        ("reportDate", ""),
        ("institutionName", "中山市鑫普达检测有限公司"),
        ("footerCompanyName", "中山市鑫普达检测有限公司"),
        (
            "footerAddress",
            "广东省中山市横栏镇环镇北路52号第1栋201房和301房 http://www.zsxdp.com",
        ),
        ("footerPhone", "4000905661"),
        ("footerRecordCode", "FO-22-03-3001"),
        ("footerWebsite", "http://www.zsxdp.com"),
    ] {
        if blank(s(p, k)) {
            p[k] = v.into();
        }
    }
}
fn real_images(p: &V) -> Result<bool> {
    for v in a(p, "images") {
        ensure!(!v.is_null(), "null image slot");
        if !tr(s(v, "path")).is_empty() {
            return Ok(true);
        }
    }
    Ok(false)
}
fn page1(r: &mut Renderer, p: &V) -> Result<()> {
    r.new_page();
    let title = s(p, "title");
    bt(
        r,
        title,
        60.,
        ((WIDTH - r.font.measure(title, 60.)?) / 2.).max(0.),
        561.44,
    )?;
    let mut y = 466.38;
    for (k, l) in [
        ("reportNumber", "报告编号："),
        ("productName", "产品名称："),
        ("modelSpecification", "型号规格："),
        ("entrustCompany", "委托单位："),
        ("testItems", "检测项目："),
        ("reportDate", "报告日期："),
    ] {
        bt(r, &format!("{l}{}", s(p, k)), 18., 117., y)?;
        y -= 31.2;
    }
    bt(r, "检测机构：", 18., 117., 216.77)?;
    bt(r, s(p, "institutionName"), 18., 200.04, 217.06)
}
fn page2(r: &mut Renderer, p: &V, photos: bool) -> Result<()> {
    let default =
        json!([{"title":"检测报告","page":1,"level":1},{"title":"目录","page":2,"level":1}]);
    let mut es = if !a(&p["page2"], "entries").is_empty() {
        a(&p["page2"], "entries").to_vec()
    } else if p["page2"].is_null() && !a(p, "toc").is_empty() {
        a(p, "toc").to_vec()
    } else {
        default.as_array().unwrap().clone()
    };
    ensure!(es.iter().all(|e| !e.is_null()), "null TOC entry");
    if !photos {
        es.retain(|e| s(e, "title") != "样品照片");
    }
    es.sort_by_key(|e| (n(e, "page"), n(e, "level")));
    if !photos {
        let mut prev = 0;
        for e in &mut es {
            let pn = if prev == 0 { n(e, "page") } else { prev + 1 };
            e["page"] = pn.into();
            prev = pn;
        }
    }
    let top = HEIGHT - mm(55.);
    let mut y = top - 50.;
    r.new_page();
    bc(r, "目录", 32., WIDTH / 2., y)?;
    y -= 60.;
    for e in es {
        if y < mm(38.) + 40. {
            r.new_page();
            y = top - 50.;
            bc(r, "目录", 32., WIDTH / 2., y)?;
            y -= 60.;
        }
        let x = 113.4 + 20. * (n(&e, "level") - 1).max(0) as f32;
        bt(r, s(&e, "title"), 16., x, y)?;
        let ls = x + r.font.measure(s(&e, "title"), 16.)? + 4.;
        if ls < 460. {
            r.op(format!(
                "q 0.8 w [2.2 2.2] 0 d {} {} m 460 {} l S Q\n",
                num(ls),
                num(y + 6.),
                num(y + 6.)
            ));
        }
        let ps = if n(&e, "page") <= 0 {
            "-".into()
        } else {
            n(&e, "page").to_string()
        };
        bt(r, &ps, 16., 480. - r.font.measure(&ps, 16.)?, y)?;
        y -= 36.;
    }
    Ok(())
}
fn pair(p: &V) -> String {
    if p.is_null() {
        String::new()
    } else {
        format!("{}：{}", s(p, "label"), s(p, "value"))
    }
}
fn divider(r: &mut Renderer, y: &mut f32) {
    line(r, 65., *y, WIDTH - 65., *y);
    *y -= 10.;
}
fn paras(r: &mut Renderer, p: &V, key: &str, y: &mut f32) -> Result<()> {
    if a(p, key).is_empty() {
        return Ok(());
    }
    for p in a(p, key) {
        ensure!(!p.is_null(), "null paragraph");
        for l in cwrap(r, s(p, "text"), 11.5, WIDTH - 150.)? {
            bt(r, &l, 11.5, 75., *y - 11.5)?;
            *y -= 17.5;
        }
    }
    *y -= 2.;
    Ok(())
}
fn page3(r: &mut Renderer, p: &V) -> Result<()> {
    r.new_page();
    let pr = WIDTH - 65.;
    let pw = pr - 65.;
    let mut y = 735.;
    bc(r, "报告首页", 20., WIDTH / 2., y)?;
    y -= 30.;
    let pt = y;
    y -= 3.;
    y -= 6.;
    bt(r, "申请检测产品基础情况：", 12., 75., y - 17.5 / 2.)?;
    line(r, 65., y - 17.5, pr, y - 17.5);
    y -= 17.5;
    let info = a(p, "basicInfo");
    if !info.is_empty() {
        let start = y;
        y += 2.;
        let mid = 65. + (pr - 65.) / 2.;
        for row in info.chunks(2) {
            let by = y - 11. - 8.;
            bt(r, &pair(&row[0]), 11.5, 75., by)?;
            if row.len() > 1 {
                let text = pair(&row[1]);
                if !text.is_empty() {
                    bt(r, &text, 11.5, mid + 8., by)?;
                }
            }
            y -= 22.;
        }
        y -= 2.;
        y -= 4.;
        line(r, mid, start, mid, y);
    } else {
        y -= 10.;
    }
    divider(r, &mut y);
    paras(r, p, "descriptions", &mut y)?;
    divider(r, &mut y);
    paras(r, p, "modelInfo", &mut y)?;
    y -= 30.;
    divider(r, &mut y);
    y -= 10.;
    bt(r, "测试依据标准", 12., 75., y)?;
    y -= 17.5;
    let standards = a(p, "standards");
    if standards.is_empty() {
        y -= 10.;
    } else {
        for (i, v) in standards.iter().enumerate() {
            ensure!(!v.is_null(), "null standard");
            let parts = [tr(s(v, "code")), tr(s(v, "title"))]
                .into_iter()
                .filter(|s| !s.is_empty())
                .collect::<Vec<_>>();
            let mut label = parts.join(" · ");
            if label.is_empty() {
                label = tr(s(v, "reference")).into();
            }
            if label.is_empty() {
                label = "-".into();
            }
            let order = if v["order"].is_null() {
                i as i64 + 1
            } else {
                n(v, "order")
            };
            bt(r, &format!("{order}. {label}"), 11.5, 75., y)?;
            y -= 15.;
        }
        y -= 2.;
    }
    divider(r, &mut y);
    y -= 6.;
    let conclusion = s(p, "conclusion");
    let has = !blank(conclusion);
    bt(
        r,
        &format!("测试结论：{}", if has { tr(conclusion) } else { "" }),
        11.5,
        75.,
        y,
    )?;
    y -= if has { 22. } else { 11. };
    let sig_top = y + 14.;
    y = sig_top;
    divider(r, &mut y);
    bt(r, "", 12., 75., y)?;
    y -= 17.5;
    let slots = a(p, "signatures");
    let mut dx = (65. + pr) / 2.;
    if !slots.is_empty() {
        let full = (pr - 65.) * 0.82;
        let sl = 65. + (pr - 65. - full) / 2.;
        let sr = sl + full;
        let il = sl + 2. - 10.;
        let ir = sr - 2.;
        let aw = ir - il;
        let lw = aw * 0.65;
        let rw = aw - lw;
        dx = il + lw;
        let rh = slots.len() as f32 * 30.;
        let bh = (rh + 1.).max(11. * 2.1 + 1.).max(120.);
        let btop = y + 24.;
        let bb = btop - bh;
        let extra = (bh - 1. - rh).max(0.);
        y = btop - 1. / 2. - extra / 2.;
        for v in slots {
            ensure!(!v.is_null(), "null signature slot");
            let role = s(v, "role");
            let rt = if blank(role) {
                String::new()
            } else {
                format!("{role}：")
            };
            let dl = if v["dateLabel"].is_null() {
                "日期"
            } else {
                s(v, "dateLabel")
            };
            let by = y - 11. + 11. / 4.;
            let rx = il
                + if ["编制", "审核", "批准"].contains(&tr(role)) {
                    -26.
                } else {
                    0.
                };
            bt(r, &rt, 11., rx, by)?;
            let sx = rx + r.font.measure(&rt, 11.)? + 60.;
            bt(r, "签名：", 11., sx, by)?;
            bt(
                r,
                &format!("{dl}："),
                11.,
                sx + r.font.measure("签名：", 11.)? + 60.,
                by,
            )?;
            y -= 30.;
        }
        let cx = dx + rw / 2. + 20.;
        let cy = bb + bh / 2.;
        let sy = cy + 11. / 2. - 18.;
        bc(r, "中山市鑫普达检测有限公司", 11., cx, sy)?;
        bc(r, "（盖章）", 11., cx, sy - 11.)?;
        y = bb + 8.;
    }
    let sig_bottom = y + 8.;
    y = sig_bottom;
    let remark = s(p, "remark");
    if !blank(remark) {
        divider(r, &mut y);
    }
    if !slots.is_empty() {
        line(r, dx, sig_top, dx, sig_bottom);
    }
    if !blank(remark) {
        y -= 9.5;
        bt(r, &format!("备注：{}", tr(remark)), 11.5, 75., y)?;
        y -= 10.;
    }
    if pt > y + 2. {
        rect(r, 65., y, pw, pt - y);
    }
    Ok(())
}
const LAMP: &[(&str, &str)] = &[
    ("indoor", "室内灯具"),
    ("outdoor", "室外灯具"),
    ("road", "道路灯具"),
    ("flood", "投光灯具"),
    ("module", "模块"),
    ("other", "其他"),
];
const LIGHT: &[(&str, &str)] = &[
    ("led", "LED"),
    ("low-pressure-mercury", "低压汞灯"),
    ("fluorescent", "荧光灯"),
    ("high-pressure-sodium", "高压钠灯"),
    ("metal-halide", "金卤灯"),
    ("high-pressure-mercury", "高压汞灯"),
    ("halogen", "卤素灯"),
    ("other", "其他"),
    ("tungsten", "钨丝灯"),
];
const TEST: &[(&str, &str)] = &[
    ("electrical", "电参数"),
    ("cct", "色温"),
    ("luminous-flux", "光通量"),
    ("cri", "显色指数"),
    ("efficacy", "灯具光效"),
    ("chromaticity", "色坐标"),
    ("distribution", "配光曲线"),
    ("color-deviation", "色容差"),
    ("peak-intensity", "峰值光强"),
    ("beam-angle", "光束角"),
    ("other", "其他"),
];
fn check_height(r: &Renderer, opts: &[(&str, &str)], w: f32, cols: usize) -> Result<f32> {
    if cols > 1 {
        return Ok(26f32.max(opts.len().div_ceil(cols).max(1) as f32 * 18. + 8.));
    }
    let mut x = 0.;
    let mut lines = 1;
    for (_, s) in opts {
        let tw = 14. + r.font.measure(s, 10.)?;
        if x + tw > w {
            lines += 1;
            x = 0.;
        }
        x += tw + 16.;
    }
    Ok(26f32.max(lines as f32 * 18. + 12.))
}
fn checks(
    r: &mut Renderer,
    opts: &[(&str, &str)],
    selected: &[V],
    mut x: f32,
    top: f32,
    w: f32,
    cols: usize,
) -> Result<()> {
    let sx = x;
    let mut cy = top - 10.;
    for (i, (key, label)) in opts.iter().enumerate() {
        if cols > 1 {
            let rows = opts.len().div_ceil(cols).max(1);
            let cw = (w - (cols - 1) as f32 * 16.) / cols as f32;
            x = sx + (i / rows) as f32 * (cw + 16.);
            cy = top - 9. - 2. - (i % rows) as f32 * 18.;
        } else {
            let tw = 14. + r.font.measure(label, 10.)?;
            if x + tw > sx + w {
                x = sx;
                cy -= 18.;
            }
        }
        let by = cy - 5.;
        rect(r, x, by, 10., 10.);
        if selected.iter().any(|v| v.as_str() == Some(key)) {
            r.op(format!(
                "1 w {} {} m {} {} l {} {} l S\n",
                num(x + 2.),
                num(by + 5.),
                num(x + 4.5),
                num(by + 2.),
                num(x + 8.),
                num(by + 7.5)
            ));
        }
        bt(r, label, 10., x + 14., cy - 5. + 1.5)?;
        if cols <= 1 {
            x += 14. + r.font.measure(label, 10.)? + 16.;
        }
    }
    Ok(())
}
fn cell(r: &mut Renderer, s: &str, x: f32, y: f32, w: f32, h: f32) -> Result<()> {
    rect(r, x, y - h, w, h);
    bt(
        r,
        s,
        10.,
        x + (w - r.font.measure(s, 10.)?) / 2.,
        y - h / 2. - 5. + 2.,
    )
}
fn dual(r: &mut Renderer, y: &mut f32, l: &str, v: &str, rr: &str, v2: &str) -> Result<()> {
    let cw = (WIDTH - 120.) / 2.;
    cell(r, l, 60., *y, 90., 26.)?;
    cell(r, v, 150., *y, cw - 90., 26.)?;
    cell(r, rr, 60. + cw, *y, 90., 26.)?;
    cell(r, v2, 60. + cw + 90., *y, cw - 90., 26.)?;
    *y -= 26.;
    Ok(())
}
fn plain(r: &mut Renderer, y: &mut f32, s: &str) -> Result<()> {
    rect(r, 60., *y - 26., WIDTH - 120., 26.);
    bt(r, s, 10., 68., *y - 13. - 5. + 2.)?;
    *y -= 26.;
    Ok(())
}
fn unit(s: &str, u: &str) -> String {
    let v = tr(nb(s, "--"));
    if v.ends_with(u) {
        v.into()
    } else {
        format!("{v}{u}")
    }
}
fn page4(r: &mut Renderer, p: &V) -> Result<()> {
    r.new_page();
    let pw = WIDTH - 120.;
    let mut y = 735.;
    bc(r, "样品描述", 20., WIDTH / 2., y)?;
    y -= 20.;
    let pt = y;
    let lh = check_height(r, LAMP, pw - 90., 0)?;
    rect(r, 60., y - lh, pw, lh);
    line(r, 150., y, 150., y - lh);
    cell(r, "灯具类型：", 60., y, 90., lh)?;
    checks(r, LAMP, a(p, "lampTypes"), 156., y - 4., pw - 102., 0)?;
    y -= lh;
    let rated = &p["rated"];
    dual(
        r,
        &mut y,
        "额定电压：",
        &unit(s(rated, "voltage"), "V"),
        "额定频率：",
        &unit(s(rated, "frequency"), "Hz"),
    )?;
    dual(
        r,
        &mut y,
        "额定功率：",
        &unit(s(rated, "power"), "W"),
        "额定色温：",
        nb(s(rated, "cct"), "--"),
    )?;
    dual(
        r,
        &mut y,
        "外观尺寸：",
        nb(s(p, "dimensions"), "--"),
        "",
        "",
    )?;
    dual(
        r,
        &mut y,
        "发光口面积：",
        nb(s(p, "luminousPortArea"), "--"),
        "",
        "",
    )?;
    let hh = check_height(r, LIGHT, pw - 90., 2)?;
    rect(r, 60., y - hh, pw, hh);
    line(r, 150., y, 150., y - hh);
    cell(r, "光源类型：", 60., y, 90., hh)?;
    checks(r, LIGHT, a(p, "lightSourceTypes"), 156., y, pw - 102., 2)?;
    y -= hh;
    let remarks = nb(s(p, "remarks"), "--");
    plain(r, &mut y, &format!("备注：{remarks}"))?;
    rect(r, 60., y, pw, pt - y);
    y -= 60.;
    bc(r, "检测项目", 20., WIDTH / 2., y)?;
    y -= 20.;
    let pt = y;
    let hh = check_height(r, TEST, pw - 102., 2)?;
    rect(r, 60., y - hh, pw, hh);
    checks(r, TEST, a(p, "testItems"), 156., y, pw - 102., 2)?;
    y -= hh;
    let remark = nb(s(p, "detectionRemark"), nb(s(p, "testItemsOther"), remarks));
    plain(r, &mut y, &format!("备注：{remark}"))?;
    rect(r, 60., y, pw, pt - y);
    Ok(())
}
const COLS: [f32; 6] = [38., 134., 92., 104., 66., 50.];
fn device_header(r: &mut Renderer, y: f32) -> Result<()> {
    let labels: Vec<Vec<&str>> = vec![
        vec!["序号"],
        vec!["名称"],
        vec!["型号"],
        vec!["仪器编号"],
        vec!["校准", "有效期"],
        vec!["本次使用", "(√)"],
    ];
    let mut x = 60.;
    for (i, w) in COLS.iter().enumerate() {
        rect(r, x, y - 32., *w, 32.);
        let sy = y - (32. - labels[i].len() as f32 * 12.) / 2. - 10.;
        for (j, s) in labels[i].iter().enumerate() {
            bt(
                r,
                s,
                10.,
                x + (*w - r.font.measure(s, 10.)?) / 2.,
                sy - j as f32 * 12.,
            )?;
        }
        x += w;
    }
    Ok(())
}
fn page6(r: &mut Renderer, p: &V, seven: &V) -> Result<()> {
    r.new_page();
    let mut y = 735.;
    bc(r, "实验仪器设备清单", 18., WIDTH / 2., y)?;
    y -= 24.;
    device_header(r, y)?;
    y -= 32.;
    for (i, v) in a(p, "devices").iter().enumerate() {
        ensure!(!v.is_null(), "null device");
        let order = format!("{}.", i + 1);
        let values: [&str; 6] = [
            order.as_str(),
            s(v, "name"),
            s(v, "model"),
            s(v, "deviceNo"),
            s(v, "calibrationDue"),
            if v["isUsed"].as_bool() == Some(true) {
                "√"
            } else {
                ""
            },
        ];
        let mut lines = vec![];
        let mut max_lines = 1;
        for (j, s) in values.iter().enumerate() {
            let ls = cwrap(r, s, 10., COLS[j] - 12.)?;
            if (1..=4).contains(&j) {
                max_lines = max_lines.max(ls.len());
            }
            lines.push(ls);
        }
        let rh = 24f32.max(max_lines as f32 * 12. + 6.);
        if y - rh < mm(38.) + 60. {
            r.new_page();
            y = HEIGHT - mm(55.) - 40.;
            device_header(r, y)?;
            y -= 32.;
        }
        let mut x = 60.;
        for (j, w) in COLS.iter().enumerate() {
            rect(r, x, y - rh, *w, rh);
            let sy = y - (rh - lines[j].len() as f32 * 12.) / 2. - 10.;
            for (k, s) in lines[j].iter().enumerate() {
                bt(
                    r,
                    s,
                    10.,
                    x + (*w - r.font.measure(s, 10.)?) / 2.,
                    sy - k as f32 * 12.,
                )?;
            }
            x += w;
        }
        y -= rh;
    }
    if !p["notes"].is_null() {
        y -= 16.;
        for v in a(p, "notes") {
            bt(r, v.as_str().unwrap_or(""), 10., 60., y)?;
            y -= 14.;
        }
    }
    if !seven.is_null() {
        y -= 20.;
        let points = a(seven, "bulletPoints");
        if !points.is_empty() {
            if y - 24. < mm(38.) + 40. {
                return Ok(());
            }
            bt(r, "测试条件：", 10., 60., y - 10.)?;
            y -= 24.;
            for (i, v) in points.iter().enumerate() {
                if y - 24. < mm(38.) + 40. {
                    break;
                }
                bt(
                    r,
                    &format!("{}. {}", i + 1, v.as_str().unwrap_or("null")),
                    10.,
                    60.,
                    y - 10.,
                )?;
                y -= 24.;
            }
        } else {
            for v in a(&seven["statement"], "lines") {
                if y - 24. < mm(38.) + 40. {
                    break;
                }
                bt(r, v.as_str().unwrap_or(""), 10., 60., y - 10.)?;
                y -= 24.;
            }
        }
    }
    Ok(())
}
fn img(r: &mut Renderer, path: &str, x: f32, y: f32, w: f32, h: f32) -> Result<bool> {
    let bytes = match fs::read(path) {
        Ok(v) => v,
        Err(_) => return Ok(false),
    };
    let im = match lims_images::decode(&bytes) {
        Ok(v) => v,
        Err(_) => return Ok(false),
    };
    let scale = (w / im.width() as f32).min(h / im.height() as f32);
    let dw = im.width() as f32 * scale;
    let dh = im.height() as f32 * scale;
    Ok(
        r.image_at(&bytes, x + (w - dw) / 2., y + (h - dh) / 2., dw, dh)
            .is_ok(),
    )
}
fn page5(r: &mut Renderer, p: &V) -> Result<()> {
    let mut slots = a(p, "images").to_vec();
    ensure!(slots.iter().all(|s| !s.is_null()), "null image slot");
    slots.sort_by_key(|s| (n(s, "pageIndex"), n(s, "slot")));
    for (i, v) in slots.iter().enumerate() {
        if i % 2 == 0 {
            r.new_page();
            bc(r, "样品照片", 20., WIDTH / 2., 735.)?;
        }
        let by = 715. - (i % 2) as f32 * 272. - 240.;
        let w = WIDTH - 120.;
        rect(r, 60., by, w, 240.);
        let iw = w - 16.;
        let ih = 208.;
        let iy = by + 24.;
        let ix = 68.;
        if !img(r, s(v, "path"), ix, iy, iw, ih)? && s(v, "caption") != "暂无样品图片" {
            rect(r, ix, iy, iw, ih);
            bc(r, "图片缺失", 12., ix + iw / 2., iy + ih / 2.)?;
        }
        bc(r, s(v, "caption"), 12., 60. + w / 2., by + 8.)?;
    }
    Ok(())
}
fn path_value(p: &V, path: &str) -> String {
    let mut q = p;
    for k in path.split('.') {
        q = &q[k];
    }
    match q {
        V::Null => String::new(),
        V::String(v) => v.clone(),
        _ => q.to_string(),
    }
}
fn gb_cell(r: &mut Renderer, s: &str, x: f32, y: f32, w: f32, h: f32) -> Result<()> {
    rect(r, x, y, w, h);
    bt(
        r,
        s,
        11.,
        x + ((w - r.font.measure(s, 11.)?) / 2.).max(2.),
        y + (h - 11.) / 2. + 2.,
    )
}
fn gb_table(
    r: &mut Renderer,
    y: &mut f32,
    title: &str,
    headers: &[&str],
    rows: Vec<Vec<String>>,
    simple: bool,
) -> Result<()> {
    bc(r, title, 14., WIDTH / 2., *y)?;
    *y -= 16.;
    let (hh, rh) = if simple { (30., 26.) } else { (28., 24.) };
    let cw = (WIDTH - 120.) / headers.len() as f32;
    for (i, s) in headers.iter().enumerate() {
        gb_cell(r, s, 60. + i as f32 * cw, *y - hh, cw, hh)?;
    }
    *y -= hh;
    for row in rows {
        for (i, s) in row.iter().enumerate() {
            gb_cell(r, s, 60. + i as f32 * cw, *y - rh, cw, rh)?;
        }
        *y -= rh;
    }
    *y -= 10.;
    Ok(())
}
fn gb(r: &mut Renderer, d: &V) -> Result<()> {
    r.new_page();
    let mut y = 735.;
    bc(r, "测试报告", 18., WIDTH / 2., y)?;
    y -= 26.;
    let v = |p: &str| path_value(d, p);
    let owned = |a: &[&str]| a.iter().map(|s| s.to_string()).collect::<Vec<_>>();
    gb_table(
        r,
        &mut y,
        "电参数",
        &["电压(V)", "电流(A)", "功率(W)", "功率因数", "备注"],
        vec![vec![
            v("electrical.voltage"),
            v("electrical.current"),
            v("electrical.power"),
            v("electrical.powerFactor"),
            v("electrical.remark"),
        ]],
        true,
    )?;
    y -= 12.;
    gb_table(
        r,
        &mut y,
        "色度参数",
        &["序号", "项目", "数据", "备注"],
        vec![
            vec!["1".into(), "色温(K)".into(), v("chroma.cct"), "".into()],
            vec!["2".into(), "显色指数".into(), v("chroma.cri"), "".into()],
            vec!["3".into(), "红色饱和 R9".into(), v("chroma.r9"), "".into()],
            vec!["4".into(), "色度坐标 x".into(), v("chroma.cx"), "".into()],
            vec!["".into(), "色度坐标 y".into(), v("chroma.cy"), "".into()],
            vec![
                "5".into(),
                "色容差(SDCM)".into(),
                v("chroma.sdcm"),
                v("chroma.remark"),
            ],
        ],
        false,
    )?;
    y -= 12.;
    gb_table(
        r,
        &mut y,
        "光度参数",
        &["序号", "项目", "数据", "备注"],
        vec![
            vec![
                "1".into(),
                "光通量(LM)".into(),
                v("photometry.lumen"),
                "".into(),
            ],
            vec![
                "2".into(),
                "灯具光效(LM/W)".into(),
                v("photometry.efficiency"),
                "".into(),
            ],
            vec![
                "3".into(),
                "峰值光强(cd)".into(),
                v("photometry.peak"),
                "".into(),
            ],
            owned(&["4", "光束角(50%)", "", ""]),
            vec![
                "".into(),
                "C0/C180".into(),
                v("photometry.beamC0"),
                "".into(),
            ],
            vec![
                "".into(),
                "C90/C270".into(),
                v("photometry.beamC90"),
                "".into(),
            ],
            vec![
                "".into(),
                "平均值".into(),
                v("photometry.beamAvg"),
                v("photometry.remark"),
            ],
        ],
        false,
    )
}
fn header_footer(r: &mut Renderer, p: &V, index: usize, total: usize) -> Result<()> {
    let right = WIDTH - 60.;
    r.op(format!("q 1 g 0 760 {} 120 re f Q\n", num(WIDTH)));
    bt(r, s(p, "headerCompanyName"), 16., 76.26, 785.34)?;
    let t = s(p, "headerReportNumber");
    bt(r, t, 14., right - r.font.measure(t, 14.)?, 785.02)?;
    let rule = |r: &mut Renderer, y: f32| {
        r.op(format!(
            "q 1.4 w 0 0.4 0.8 RG 60 {} m {} {} l S Q\n",
            num(y),
            num(right),
            num(y)
        ));
    };
    rule(r, 780.);
    r.op(format!("q 1 g 0 0 {} 80 re f Q\n", num(WIDTH)));
    rule(r, 60.);
    bt(r, s(p, "footerCompanyName"), 10.5, 199.68, 45.38)?;
    let page = format!("第 {index} 页 共 {total} 页");
    bt(r, &page, 9., right - r.font.measure(&page, 9.)?, 44.23)?;
    let address = s(p, "footerAddress");
    let website = s(p, "footerWebsite");
    let phone = s(p, "footerPhone");
    bt(r, address, 10.5, 72., 30.23)?;
    let ae = 72. + r.font.measure(address, 10.5)?;
    let ps = right - r.font.measure(phone, 10.5)?;
    bt(r, phone, 10.5, ps, 30.23)?;
    if !website.is_empty() {
        let (x, y) = if ae + 10. + r.font.measure(website, 10.5)? <= ps - 10. {
            ((ae + 10.).max(72.), 30.23)
        } else {
            (72., 30.23 - (10.5 + 2.))
        };
        bt(r, website, 10.5, x, y)?;
    }
    let code = s(p, "footerRecordCode");
    bt(r, code, 10.5, right - r.font.measure(code, 10.5)?, 16.61)
}
// Append source pages natively, preserving boxes/resources/annotations. Overlay uses the
// original Java fixed A4 coordinates even on rotated/cropped/non-A4 pages (known defect).
fn finish(
    mut r: Renderer,
    p: &V,
    mut appendices: Vec<(usize, Document)>,
    output: &Path,
) -> Result<usize> {
    let native = r.pages.len();
    let total = native
        + appendices
            .iter()
            .map(|(_, d)| d.get_pages().len())
            .sum::<usize>();
    for i in 0..native {
        r.page = Some(i);
        let inserted = appendices
            .iter()
            .filter(|(pos, _)| *pos <= i)
            .map(|(_, d)| d.get_pages().len())
            .sum::<usize>();
        header_footer(&mut r, p, i + 1 + inserted, total)?;
    }
    let mut overlays = vec![];
    let mut earlier = 0;
    for (pos, document) in &appendices {
        for j in 0..document.get_pages().len() {
            r.new_page();
            header_footer(&mut r, p, pos + earlier + j + 1, total)?;
            overlays.push(r.pages.pop().unwrap().content.replace("/F1 ", "/CF1 "));
        }
        earlier += document.get_pages().len();
    }
    let pages_id = r.doc.new_object_id();
    let font = r.font.embed(&mut r.doc)?;
    let mut ids = vec![];
    for page in r.pages {
        let mut resources = dictionary! {"Font"=>dictionary!{"F1"=>font}};
        if !page.images.is_empty() {
            let mut imgs = Dictionary::new();
            for (name, id) in page.images {
                imgs.set(name, id);
            }
            resources.set("XObject", imgs);
        }
        let contents = r
            .doc
            .add_object(Stream::new(Dictionary::new(), page.content.into_bytes()));
        let id=r.doc.add_object(dictionary!{"Type"=>"Page","Parent"=>pages_id,"MediaBox"=>arr(&[0.,0.,WIDTH,HEIGHT]),"Resources"=>resources,"Contents"=>contents});
        ids.push(Object::Reference(id));
    }
    let native_ids = ids.clone();
    let mut inserted_ids = vec![];
    let mut overlay_index = 0;
    let mut appendix_catalog = appendix::CatalogMerge::default();
    for (pos, appendix) in &mut appendices {
        let mut doc_ids = vec![];
        appendix.renumber_objects_with(r.doc.max_id + 1);
        appendix_catalog.capture(appendix)?;
        let page_ids = appendix.get_pages();
        for id in page_ids.values() {
            let mut d = appendix.get_dictionary(*id)?.clone();
            for key in [b"MediaBox".as_slice(), b"CropBox", b"Resources", b"Rotate"] {
                if !d.has(key)
                    && let Some(v) = inherit(appendix, *id, key)?
                {
                    d.set(key, v);
                }
            }
            d.set("Parent", pages_id);
            appendix.objects.insert(*id, Object::Dictionary(d));
        }
        r.doc.max_id = appendix.max_id;
        r.doc.objects.extend(std::mem::take(&mut appendix.objects));
        for (_, id) in page_ids {
            let mut d = r.doc.get_dictionary(id)?.clone();
            let mut res = match d.get(b"Resources") {
                Ok(v) => r.doc.dereference(v)?.1.as_dict()?.clone(),
                Err(_) => Dictionary::new(),
            };
            let mut fonts = match res.get(b"Font") {
                Ok(v) => r.doc.dereference(v)?.1.as_dict()?.clone(),
                Err(_) => Dictionary::new(),
            };
            fonts.set("CF1", font);
            res.set("Font", fonts);
            d.set("Resources", res);
            let prefix = r
                .doc
                .add_object(Stream::new(Dictionary::new(), b"q\n".to_vec()));
            let overlay = r.doc.add_object(Stream::new(
                Dictionary::new(),
                format!("\nQ\n{}", overlays[overlay_index]).into_bytes(),
            ));
            overlay_index += 1;
            let mut contents = vec![Object::Reference(prefix)];
            if let Ok(v) = d.get(b"Contents") {
                match r.doc.dereference(v)?.1 {
                    Object::Array(a) => contents.extend(a.clone()),
                    _ => contents.push(v.clone()),
                }
            }
            contents.push(Object::Reference(overlay));
            d.set("Contents", Object::Array(contents));
            r.doc.objects.insert(id, Object::Dictionary(d));
            doc_ids.push(Object::Reference(id));
        }
        inserted_ids.push((*pos, doc_ids));
    }
    ids.clear();
    for (i, native_id) in native_ids
        .iter()
        .map(Some)
        .chain(std::iter::once(None))
        .enumerate()
    {
        for (_, extra) in inserted_ids.iter().filter(|(pos, _)| *pos == i) {
            ids.extend(extra.clone());
        }
        if let Some(id) = native_id {
            ids.push(id.clone());
        }
    }
    r.doc.objects.insert(
        pages_id,
        Object::Dictionary(dictionary! {"Type"=>"Pages","Kids"=>ids,"Count"=>total as i64}),
    );
    let mut catalog = dictionary! {"Type"=>"Catalog","Pages"=>pages_id};
    appendix_catalog.attach(&mut r.doc, &mut catalog)?;
    let root = r.doc.add_object(catalog);
    r.doc.trailer.set("Root", root);
    let title = if s(p, "headerReportNumber").is_empty() {
        "检测合同"
    } else {
        s(p, "headerReportNumber")
    };
    let author = if s(p, "headerCompanyName").is_empty() {
        "检测机构"
    } else {
        s(p, "headerCompanyName")
    };
    let info=r.doc.add_object(dictionary!{"Title"=>utf16(title),"Author"=>utf16(author),"Creator"=>utf16("中山市鑫普达检测有限公司"),"Producer"=>utf16("鑫普达LIMS系统"),"Subject"=>utf16("检测合同文件"),"Keywords"=>utf16("检测,合同,认证")});
    r.doc.trailer.set("Info", info);
    r.doc.prune_objects();
    r.doc.compress();
    if let Some(parent) = output.parent() {
        fs::create_dir_all(parent)?;
    }
    r.doc.save(output)?;
    Ok(total)
}
fn external(t: &V) -> Result<Option<Document>> {
    let path = [
        s(t, "pdf_path"),
        s(t, "config_pdf_path"),
        s(t, "pdf_url"),
        s(&t["data"], "pdf_url"),
    ]
    .into_iter()
    .find(|s| !blank(s))
    .unwrap_or("");
    if path.is_empty() {
        return Ok(None);
    }
    ensure!(!path.contains('\0'), "Invalid NUL in template path");
    let bytes = if path.starts_with("http://") || path.starts_with("https://") {
        let bytes = assets::load_contract_asset(path)?;
        if bytes.is_empty() {
            return Ok(None);
        }
        bytes
    } else {
        if !Path::new(path).exists() {
            return Ok(None);
        }
        fs::read(path)?
    };
    let mut doc = Document::load_mem(&bytes)?;
    if doc.is_encrypted() {
        doc.decrypt("")?;
    }
    Ok(Some(doc))
}
pub(super) fn render(
    mut p: V,
    font: Arc<FontData>,
    _heading: Option<Arc<FontData>>,
    output: &Path,
) -> Result<usize> {
    let sample = sample()?;
    if p.is_null() {
        p = sample.clone();
    }
    let original_photos = real_images(&p["page5"])?;
    for k in ["page1", "page3", "page4", "page5", "page6", "page7"] {
        if p[k].is_null() {
            p[k] = sample[k].clone();
        }
    }
    defaults(&mut p["page1"]);
    let one = p["page1"].clone();
    let mut r = Renderer::new(font, None);
    page1(&mut r, &one)?;
    page2(&mut r, &p, original_photos)?;
    page3(&mut r, &p["page3"])?;
    page4(&mut r, &p["page4"])?;
    page6(&mut r, &p["page6"], &p["page7"])?;
    if real_images(&p["page5"])? {
        page5(&mut r, &p["page5"])?;
    }
    let mut appendices = vec![];
    for t in a(&p, "templates") {
        if t.is_null() || t["type"].is_null() {
            continue;
        }
        let custom = s(t, "renderer_key").to_lowercase() == "gb70001-2015-2";
        if custom {
            let data = if p["template_data"].is_null() {
                &p["templateData"]
            } else {
                &p["template_data"]
            };
            gb(&mut r, &data[s(t, "code")])?;
        }
        if (custom || tr(s(t, "type")).eq_ignore_ascii_case("pdf_append"))
            && let Some(d) = external(t)?
        {
            appendices.push((r.pages.len(), d));
        }
    }
    finish(r, &one, appendices, output)
}
