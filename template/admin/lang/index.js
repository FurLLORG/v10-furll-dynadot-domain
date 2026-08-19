(function () {
  const module_lang = {
    "zh-cn": {
      furll_domain_config_title: "Dynadot 接口配置",
      furll_domain_config_server: "当前接口",
      furll_domain_no_server: "当前商品未关联可用的 Dynadot 接口",
      furll_domain_mode: "运行模式",
      furll_domain_mode_tip:
        "生产模式请求 https://api.dynadot.com，沙盒模式请求 https://api-sandbox.dynadot.com",
      furll_domain_mode_production: "生产模式",
      furll_domain_mode_sandbox: "沙盒模式",
      furll_domain_api_key: "API 访问标识（API Production Key）",
      furll_domain_api_key_placeholder: "请输入 Dynadot API Production Key",
      furll_domain_api_secret: "密钥（Secret Key）",
      furll_domain_api_secret_placeholder: "请输入 Dynadot Secret Key（留空保持不变）",
      furll_domain_api_secret_configured: "密钥已配置；留空保存可保持原值",
      furll_domain_save: "保存配置",
      furll_domain_load_fail: "获取配置失败",
      furll_domain_save_fail: "保存配置失败",
    },
    "en-us": {
      furll_domain_config_title: "Dynadot Interface Config",
      furll_domain_config_server: "Current interface",
      furll_domain_no_server: "No Dynadot interface is associated with this product",
      furll_domain_mode: "Running mode",
      furll_domain_mode_tip:
        "Production mode uses https://api.dynadot.com, sandbox mode uses https://api-sandbox.dynadot.com",
      furll_domain_mode_production: "Production",
      furll_domain_mode_sandbox: "Sandbox",
      furll_domain_api_key: "API Access Key (API Production Key)",
      furll_domain_api_key_placeholder: "Enter the Dynadot API Production Key",
      furll_domain_api_secret: "Secret Key",
      furll_domain_api_secret_placeholder: "Enter the Dynadot Secret Key (leave blank to keep)",
      furll_domain_api_secret_configured: "Secret is configured; leave blank to keep it",
      furll_domain_save: "Save Config",
      furll_domain_load_fail: "Failed to load config",
      furll_domain_save_fail: "Failed to save config",
    },
    "zh-hk": {
      furll_domain_config_title: "Dynadot 接口配置",
      furll_domain_config_server: "當前接口",
      furll_domain_no_server: "當前商品未關聯可用的 Dynadot 接口",
      furll_domain_mode: "運行模式",
      furll_domain_mode_tip:
        "生產模式請求 https://api.dynadot.com，沙盒模式請求 https://api-sandbox.dynadot.com",
      furll_domain_mode_production: "生產模式",
      furll_domain_mode_sandbox: "沙盒模式",
      furll_domain_api_key: "API 訪問標識（API Production Key）",
      furll_domain_api_key_placeholder: "請輸入 Dynadot API Production Key",
      furll_domain_api_secret: "密鑰（Secret Key）",
      furll_domain_api_secret_placeholder: "請輸入 Dynadot Secret Key（留空保持不變）",
      furll_domain_api_secret_configured: "密鑰已配置；留空保存可保持原值",
      furll_domain_save: "保存配置",
      furll_domain_load_fail: "獲取配置失敗",
      furll_domain_save_fail: "保存配置失敗",
    },
  };
  const DEFAULT_LANG = localStorage.getItem("backLang") || "zh-cn";
  window.module_lang = module_lang[DEFAULT_LANG];
})();
