const path = require("path");
const MiniCssExtractPlugin = require("mini-css-extract-plugin");
const HtmlWebpackPlugin = require("html-webpack-plugin");
const { CleanWebpackPlugin } = require("clean-webpack-plugin");
const BrowserSyncPlugin = require("browser-sync-webpack-plugin");
const fs = require("fs");
const autoprefixer = require("autoprefixer");
const cssnano = require("cssnano");

// Load Pug variables from JSON file
const pugVariables = require("./config/pugVariables.json");

// Resolve references in Pug variables
function resolveRefs(variables) {
  const commonSets = {};
  Object.keys(variables).forEach((key) => {
    if (key === "common") {
      commonSets[key] = variables[key];
    } else if (variables[key].$ref) {
      const ref = variables[key].$ref;
      variables[key] = { ...commonSets[ref], ...variables[key] };
      delete variables[key].$ref;
    }
  });
  return variables;
}

const resolvedPugVariables = resolveRefs(pugVariables);

console.log("Resolved Pug Variables:", resolvedPugVariables);

// Get SCSS entries dynamically
const getSCSSEntries = () => {
  const scssFolder = path.resolve(__dirname, "assets/scss");
  return fs.readdirSync(scssFolder).reduce((entries, file) => {
    if (file.endsWith(".scss")) {
      entries[path.basename(file, ".scss")] = path.resolve(scssFolder, file);
    }
    return entries;
  }, {});
};

// Generate HTML plugins dynamically
const generateHTMLPlugins = () => {
  const htmlFolder = path.resolve(__dirname, "assets/pug/pages/template");
  const plugins = [];
  const traverseDirectory = (dir) => {
    fs.readdirSync(dir).forEach((file) => {
      const filePath = path.join(dir, file);
      if (fs.statSync(filePath).isDirectory()) {
        traverseDirectory(filePath);
      } else if (file.endsWith(".pug")) {
        const name = path.basename(file, ".pug");
        const templateVars = pugVariables[name] || {};
        plugins.push(
          new HtmlWebpackPlugin({
            template: filePath,
            filename: path.resolve(
              __dirname,
              "template",
              `${path.basename(file, ".pug")}.html`
            ),
            templateParameters: templateVars,
            inject: true,
          })
        );
      }
    });
  };
  traverseDirectory(htmlFolder);
  return plugins;
};

const scssEntries = getSCSSEntries();

module.exports = {
  mode: "development",
  entry: scssEntries, // Entries for each SCSS file
  output: {
    path: path.resolve(__dirname, "assets/css"),
    filename: "[name].js", // Only required for JS files
  },
  stats: {
    children: true,
    errorDetails: true,
  },
  module: {
    rules: [
      {
        test: /\.scss$/,
        use: [
          MiniCssExtractPlugin.loader,
          "css-loader",
          {
            loader: "postcss-loader",
            options: {
              postcssOptions: {
                plugins: [autoprefixer, cssnano({ preset: "default" })],
              },
            },
          },
          {
            loader: "sass-loader",
            options: { implementation: require("sass") },
          },
        ],
      },
      {
        test: /\.pug$/,
        use: "pug-loader",
      },
      {
        test: /\.(png|svg|jpg|jpeg|gif)$/i,
        type: "asset/resource",
        generator: {
          filename: "images/[name][ext][query]",
        },
      },
    ],
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: "[name].css",
    }),
    new CleanWebpackPlugin({
      cleanOnceBeforeBuildPatterns: ["**/*", "!vendors", "!vendors/**/*"],
    }),
    ...generateHTMLPlugins(),
    new BrowserSyncPlugin(
      {
        proxy: "http://localhost/cuba/template/index.html",
        files: [{ match: ["**/*.html", "**/*.css"] }],
        reloadDelay: 0,
      },
      { reload: true }
    ),
  ],
  optimization: {
    splitChunks: false,
  },
  devServer: {
    static: { directory: path.join(__dirname, "index.html") },
    port: 3000,
    open: true,
    historyApiFallback: true,
  },
  watch: true,
};
