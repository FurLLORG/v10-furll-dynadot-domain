(function (window, undefined) {
  const template = document.getElementsByClassName("common_config")[0];
  if (!template) {
    return;
  }
  Vue.prototype.lang = Object.assign(window.lang || {}, window.module_lang || {});
  new Vue({
    components: {
      comConfig,
    },
    data() {
      return {
        serverId: "",
        serverName: "",
        submitLoading: false,
        secretConfigured: false,
        modeOptions: [
          { value: "production", label: lang.furll_domain_mode_production },
          { value: "sandbox", label: lang.furll_domain_mode_sandbox },
        ],
        form: {
          mode: "production",
          api_key: "",
          api_secret: "",
        },
        rules: {
          mode: [{ required: true }],
          api_key: [{ required: true, message: lang.furll_domain_api_key_placeholder }],
        },
      };
    },
    methods: {
      getConfig() {
        Axios.get("/furll_dynadot_domain/config", {
          params: { server_id: this.serverId },
        })
          .then((res) => {
            const data = res.data.data;
            this.form.mode = data.mode || "production";
            this.form.api_key = data.api_key || "";
            this.form.api_secret = "";
            this.secretConfigured = data.api_secret_configured === true;
            this.serverName = data.server_name || "";
          })
          .catch((error) => {
            this.$message.error(
              error.data && error.data.msg ? error.data.msg : lang.furll_domain_load_fail
            );
          });
      },
      onSubmit({ validateResult, firstError }) {
        if (validateResult !== true) {
          return;
        }
        this.submitLoading = true;
        Axios.put("/furll_dynadot_domain/config", {
          server_id: this.serverId,
          mode: this.form.mode,
          api_key: this.form.api_key,
          api_secret: this.form.api_secret,
        })
          .then((res) => {
            this.submitLoading = false;
            this.$message.success(res.data.msg);
          })
          .catch((error) => {
            this.submitLoading = false;
            this.$message.error(
              error.data && error.data.msg ? error.data.msg : lang.furll_domain_save_fail
            );
          });
      },
    },
    created() {
      const serverIdEl = document.getElementById("furll_dynadot_server_id");
      this.serverId = serverIdEl ? serverIdEl.value : "";
      if (this.serverId) {
        this.getConfig();
      }
    },
  }).$mount(template);
})(window);
