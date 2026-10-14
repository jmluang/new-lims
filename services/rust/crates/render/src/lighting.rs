//! Direct translation of LightingReportRenderer. Native text, paths and images.
use super::*;
use serde_json::Value as V;
// Literal Java baseline offset in PDF points, unrelated to the circle constant.
#[allow(clippy::approx_constant)]
const INFORMATION_BASELINE_OFFSET_PT: f32 = 3.14;
fn s<'a>(p: &'a V, k: &str) -> &'a str {
    p[k].as_str().unwrap_or("")
}
fn bytes(v: &V) -> Result<Vec<u8>> {
    match v {
        V::Null => Ok(vec![]),
        V::String(s) => Ok(base64::engine::general_purpose::STANDARD.decode(s)?),
        V::Array(a) => a
            .iter()
            .map(|n| {
                n.as_u64()
                    .filter(|n| *n < 256)
                    .map(|n| n as u8)
                    .context("Invalid image byte")
            })
            .collect(),
        _ => bail!("Image must be Base64 or byte array"),
    }
}
fn unit(v: &str, suffix: char) -> String {
    if let Some(v) = v.strip_suffix(suffix) {
        format!(
            "{} {}",
            v.trim_end_matches([' ', '\t', '\n', '\r', '\u{000b}', '\u{000c}']),
            suffix
        )
    } else {
        v.into()
    }
}
fn voltage(v: &str) -> String {
    if let Some(v) = v.strip_prefix("AC")
        && v.starts_with([' ', '\t', '\n', '\r', '\u{000b}', '\u{000c}'])
    {
        return v
            .trim_start_matches([' ', '\t', '\n', '\r', '\u{000b}', '\u{000c}'])
            .into();
    }
    v.into()
}
pub(super) fn render(
    p: V,
    font: Arc<FontData>,
    heading: Option<Arc<FontData>>,
    output: &Path,
) -> Result<usize> {
    for k in [
        "header",
        "cover",
        "form",
        "testSetup",
        "electrical",
        "colorimetry",
        "signatures",
    ] {
        ensure!(
            p[k].is_object(),
            "header, cover, form, testSetup, electrical, colorimetry and signatures are required"
        );
    }
    let h = &p["header"];
    let f = &p["form"];
    let c = &p["cover"];
    let sign = &p["signatures"];
    let t = &p["testSetup"];
    let e = &p["electrical"];
    let co = &p["colorimetry"];
    let mut r = Renderer::new(font, heading);
    let mut pages = HashMap::<&str, usize>::new();
    r.new_page();
    let title = "检测报告";
    let spacing = 14.113;
    let x = (WIDTH - (r.font.measure(title, 36.)? + spacing * 3.)) / 2.;
    r.text_raw(title, 36., x, 236.02, BLACK, false, Some(spacing))?;
    let fields = [
        ("报告编号：", s(c, "reportNumber")),
        ("申请人：", s(f, "applicantName")),
        ("参考标准：", s(f, "referenceStandard")),
        ("产品名称：", s(f, "productName")),
        ("型号：", s(f, "model")),
        ("接收日期：", s(c, "receivedDate")),
        ("签发日期：", s(c, "issuedDate")),
    ];
    let value_tops = [319.77, 345.87, 365.38, 399.57, 425.67, 451.77, 477.87];
    let mut extra = 0.;
    let mut top = 319.77;
    for (i, (label, value)) in fields.iter().enumerate() {
        let size = if i == 2 { 12. } else { 11. };
        let mut lines = r.wrap(value, size, 263.)?;
        if i == 2 && value.starts_with("Commission test  ") {
            lines = vec!["Commission test".into()];
            lines.extend(r.wrap(&value["Commission test  ".len()..], 12., 263.)?);
        }
        top = value_tops[i] + extra;
        let label_top = if i == 2 { top + 7.99 } else { top };
        r.text(label, 11., 132.75, label_top)?;
        let value_top = if i == 2 && lines.len() == 1 {
            label_top - 0.5
        } else {
            top
        };
        r.lines(&lines, size, 211.25, value_top, 13.8, false, 263.)?;
        let expected = if i == 2 { 2 } else { 1 };
        extra += lines.len().saturating_sub(expected) as f32 * 13.8;
        top += lines.len() as f32 * 13.8;
    }
    let company_top = 598.7f32.max(top + 48.);
    ensure!(
        company_top + 28. <= BOTTOM,
        "Cover values exceed the report cover capacity"
    );
    r.center(s(h, "company"), 15., WIDTH / 2., company_top)?;
    r.new_page();
    r.new_page();
    pages.insert("general", r.pages.len());
    pages.insert("applicant", r.pages.len());
    let info = [
        ("申请人名称", "applicantName"),
        ("地址", "applicantAddress"),
        ("制造商", "manufacturerName"),
        ("地址", "manufacturerAddress"),
        ("产品名称", "productName"),
        ("型号", "model"),
        ("参考标准", "referenceStandard"),
        ("品牌", "brand"),
        ("样品数量", "sampleQuantity"),
        ("额定电压", "ratedVoltage"),
        ("额定功率", "ratedPower"),
        ("标称光通量", "nominalLuminousFlux"),
        ("标称色温", "nominalCct"),
        ("检测实验室", "laboratoryName"),
        ("地址", "laboratoryAddress"),
        ("测试项目", "testItem"),
        ("接收日期", "receivedDate"),
        ("测试周期", "testPeriod"),
    ];
    top = 91.4;
    for (i, (label, key)) in info.iter().enumerate() {
        let lines = r.wrap(s(f, key), 12., 59.7 + 476. - 228.15 - 6.)?;
        let rh = 20.5f32.max(lines.len() as f32 * 15. + 0.5);
        ensure!(
            rh <= BOTTOM - 91.4,
            "Information value is too long for one report page"
        );
        if top + rh > BOTTOM {
            r.new_page();
            top = 91.4;
        }
        if i == 4 {
            pages.insert("product", r.pages.len());
        }
        if i == 13 {
            pages.insert("laboratory", r.pages.len());
        }
        r.text(label, 12., 65.75, top + (rh - 12.) / 2. + 1.64)?;
        r.text(":", 12., 195.05, top + (rh - 12.) / 2. + 1.64)?;
        r.lines(
            &lines,
            12.,
            228.15,
            top + (rh - lines.len() as f32 * 15.) / 2. + INFORMATION_BASELINE_OFFSET_PT,
            15.,
            false,
            0.,
        )?;
        r.rule(59.7, 59.7 + 476., top + rh, 0.5, GRAY);
        top += rh;
    }
    let names = [
        s(sign, "testedBy"),
        s(sign, "reviewedBy"),
        s(sign, "approvedBy"),
    ];
    let mut signer_lines = vec![];
    let mut gaps = [41.7f32, 31.35, 28.1];
    let mut sh = 15.;
    for i in 0..3 {
        let l = r.wrap(names[i], 12., 97.75)?;
        gaps[i] = gaps[i].max(l.len() as f32 * 15. + 9.);
        sh += gaps[i];
        signer_lines.push(l);
    }
    ensure!(
        sh <= BOTTOM - 100.,
        "Signer names exceed the signing section capacity"
    );
    let mut st = 535.84f32.max(top + 43.);
    if st + sh > BOTTOM {
        r.new_page();
        st = 100.;
    }
    pages.insert("approval", r.pages.len());
    let offsets = [0., gaps[0], gaps[0] + gaps[1]];
    let iw = [96.25, 53.95, 75.1];
    let ih = [25.3, 24.65, 24.15];
    for (i, role) in ["测试人", "审核人", "批准人"].iter().enumerate() {
        let y = st + offsets[i];
        r.text(role, 12., 65.75, y)?;
        r.text("........................", 12., 103., y)?;
        r.lines(&signer_lines[i], 12., 228.15, y, 15., false, 0.)?;
        let b = bytes(&sign[["testedImage", "reviewedImage", "approvedImage"][i]])?;
        if !b.is_empty() {
            r.image(&b, 333.9, y - 11.3, iw[i], ih[i])?;
        }
    }
    let dt = st + gaps[0] + gaps[1] + gaps[2];
    r.text("日期", 12., 65.75, dt)?;
    r.text("...............................", 12., 94., dt)?;
    r.text(s(sign, "date"), 12., 228.15, dt)?;
    let power = unit(s(f, "ratedPower"), 'W');
    let cct = unit(s(f, "nominalCct"), 'K');
    let volts = unit(&voltage(s(f, "ratedVoltage")), 'V');
    let groups: Vec<Vec<[&str; 4]>> = vec![
        vec![
            ["C 角度间隔", s(t, "cInterval"), "C 范围", s(t, "cRange")],
            [
                "γ 角度间隔",
                s(t, "gammaInterval"),
                "γ 范围",
                s(t, "gammaRange"),
            ],
            [
                "测量距离",
                s(t, "distance"),
                "角度精度",
                s(t, "angularAccuracy"),
            ],
            ["C 平面数", s(t, "cPlanes"), "γ 点数", s(t, "gammaPoints")],
        ],
        vec![
            ["产品名称", s(f, "productName"), "型号", s(f, "model")],
            [
                "样品描述",
                s(f, "sampleDescription"),
                "序列号",
                s(f, "serialNumber"),
            ],
            ["额定功率", &power, "标称色温", &cct],
            [
                "额定电压",
                &volts,
                "标称光通量",
                s(f, "nominalLuminousFlux"),
            ],
        ],
        vec![
            [
                "环境温度",
                s(f, "ambientTemperature"),
                "相对湿度",
                s(f, "relativeHumidity"),
            ],
            [
                "气流条件",
                s(f, "airVelocity"),
                "测试方向",
                s(f, "testDirection"),
            ],
            ["稳定时间", s(f, "stabilizationTime"), "", ""],
        ],
        vec![
            [
                "输入电压",
                s(e, "inputVoltage"),
                "输入电流",
                s(e, "inputCurrent"),
            ],
            [
                "输入功率",
                s(e, "inputPower"),
                "功率因数",
                s(e, "powerFactor"),
            ],
            [
                "电流谐波失真 (THD)",
                s(f, "currentThd"),
                "频率",
                s(e, "frequency"),
            ],
            [
                "电压调节率",
                s(f, "voltageRegulation"),
                "电源波形 THD",
                s(f, "supplyThd"),
            ],
        ],
        vec![
            ["相关色温 CCT", s(co, "cct"), "Duv", s(co, "duv")],
            ["显色指数 Ra", s(co, "ra"), "显色指数 R9", s(co, "r9")],
            ["色坐标 (x, y)", s(co, "xy"), "色坐标 (u', v')", s(co, "uv")],
            ["TM-30 Rf", s(co, "rf"), "TM-30 Rg", s(co, "rg")],
        ],
    ];
    r.new_page();
    top = 94.68;
    pages.insert("parameters", r.pages.len());
    for (gi, group) in groups.iter().enumerate() {
        let heading = ["测试设置", "样品信息", "测试条件", "电参数", "色度测试"][gi];
        let mut heights = vec![];
        let mut th = 0.;
        for row in group {
            let mut lines = 1;
            for v in row {
                lines = lines.max(r.wrap(v, 9., 482. / 4. - 8.)?.len());
            }
            let rh = 24.5f32.max(lines as f32 * 12. + 4.5);
            heights.push(rh);
            th += rh;
        }
        if top > 94.68 && top + 15.27 + th > BOTTOM {
            r.new_page();
            top = 94.68;
        }
        pages.insert(
            [
                "parameter0",
                "parameter1",
                "parameter2",
                "parameter3",
                "parameter4",
            ][gi],
            r.pages.len(),
        );
        r.center(heading, 9., WIDTH / 2., top)?;
        top += 15.27;
        for (ri, row) in group.iter().enumerate() {
            let rh = heights[ri];
            ensure!(
                rh <= BOTTOM - 110.,
                "Parameter value is too long for one report page"
            );
            if top + rh > BOTTOM {
                r.new_page();
                top = 94.68;
                r.center(heading, 9., WIDTH / 2., top)?;
                top += 15.27;
            }
            for (col, v) in row.iter().enumerate() {
                let x = 56.7 + col as f32 * 482. / 4.;
                r.rect(x, top, 482. / 4., rh, 0.5);
                let ls = r.wrap(v, 9., 482. / 4. - 8.)?;
                let ty = top + (rh - ((ls.len() - 1) as f32 * 12. + 9.)) / 2. + 1.5;
                r.lines(&ls, 9., x, ty, 12., true, 482. / 4.)?;
            }
            top += rh;
        }
        top += 15.23;
    }
    if let Some(photos) = p["photos"].as_array() {
        if !photos.is_empty() {
            pages.insert("photos", r.pages.len() + 1);
        }
        for group in photos.chunks(2) {
            r.new_page();
            r.center("灯具图片", 9., WIDTH / 2., 93.23)?;
            r.rect(54.7, 114., 486., group.len() as f32 * 283.95, 0.5);
            if group.len() == 2 {
                r.rule(54.7, 540.7, 397.95, 0.5, BLACK);
            }
            for (i, v) in group.iter().enumerate() {
                let b = bytes(v)?;
                ensure!(!b.is_empty(), "Photo bytes must not be empty");
                r.image(&b, 109.7, 114. + i as f32 * 283.95, 376.45, 283.45)?;
            }
        }
    }
    r.page = Some(1);
    r.heading("CONTENTS", 16., WIDTH / 2., 93.53, true)?;
    r.heading("Cover page", 12., 83.4, 165.38, false)?;
    r.heading("Contents", 12., 83.4, 187.03, false)?;
    let entries = [
        ("1", "General information", "general"),
        ("1.1", "Applicant and Manufacturer", "applicant"),
        ("1.2", "Product Information", "product"),
        ("1.3", "Laboratory and Test Information", "laboratory"),
        ("1.4", "Testing Review and Approval", "approval"),
        ("2", "Test settings and results", "parameters"),
        ("2.1", "Test Settings", "parameter0"),
        ("2.2", "Sample Information", "parameter1"),
        ("2.3", "Ambient and Test Conditions", "parameter2"),
        ("2.4", "Electrical Property Parameters", "parameter3"),
        ("2.5", "Color Parameters", "parameter4"),
        ("3", "Photos of Sample", "photos"),
    ];
    top = 220.68;
    for (n, label, key) in entries {
        if let Some(page) = pages.get(key) {
            let sub = n.contains('.');
            if !sub && top > 220.68 {
                top += 4.;
            }
            let x = if sub { 74.25 } else { 68.25 };
            let txt = format!("{n}   {label}");
            r.text(&txt, 12., x, top)?;
            let start = x + r.font.measure(&txt, 12.)? + 3.;
            let page = page.to_string();
            r.text(&page, 12., 539. - r.font.measure(&page, 12.)?, top)?;
            r.dotted(start, 531., top + 10.);
            top += 21.8;
        }
    }
    let total = r.pages.len();
    for i in 0..total {
        r.page = Some(i);
        r.colored(s(h, "company"), 18., WIDTH / 2., 43.6)?;
        r.text(&format!("文件编号:{}", s(h, "fileNumber")), 9., 435., 43.6)?;
        r.text(&format!("文件版本:{}", s(h, "version")), 9., 435., 53.95)?;
        r.rule(34., 561.25, 71.75, 1.125, [26, 57, 107]);
        r.colored(s(h, "company"), 12., 246.85, 739.69)?;
        r.center(&format!("第 {} 页  共 {total} 页", i + 1), 9., 490., 740.78)?;
        let contacts = !blank(s(h, "website")) || !blank(s(h, "email"));
        let ls = r.wrap(s(h, "address"), 9., if contacts { 172. } else { 464. })?;
        ensure!(ls.len() <= 4, "Footer address exceeds page capacity");
        r.lines(&ls, 9., 70.2, 752.45, 11., false, 0.)?;
        if !blank(s(h, "website")) {
            r.text(&format!("URL：{}", s(h, "website")), 9., 251.65, 752.45)?;
        }
        if !blank(s(h, "email")) {
            r.text(&format!("E-mail：{}", s(h, "email")), 9., 408.2, 752.45)?;
        }
    }
    r.finish_generic(
        &format!("Lighting Test Report {}", s(c, "reportNumber")),
        None,
        "New LIMS PDF Renderer",
        vec![],
        output,
    )
}
