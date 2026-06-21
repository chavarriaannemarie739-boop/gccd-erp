package com.gccd.dashboard;

import android.graphics.Color;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.Gravity;
import android.view.KeyEvent;
import android.view.View;
import android.view.WindowManager;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.TextView;
import androidx.appcompat.app.AppCompatActivity;

/**
 * 车间电视 Kiosk：原生 WebView 打开线上 /dv/，与电视浏览器行为一致。
 * 不使用 Capacitor Bridge，避免启动页/本地包在旧 WebView 上黑屏。
 */
public class MainActivity extends AppCompatActivity {

    private static final String KIOSK_URL = "https://gccd.xnssoft.com/dv/?v=103";

    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private WebView webView;
    private TextView statusView;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.parseColor("#04080f"));

        webView = new WebView(this);
        root.addView(
            webView,
            new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.MATCH_PARENT
            )
        );

        statusView = new TextView(this);
        statusView.setText("正在加载生产总览…");
        statusView.setTextColor(Color.parseColor("#8BA4C7"));
        statusView.setTextSize(18);
        statusView.setGravity(Gravity.CENTER);
        FrameLayout.LayoutParams statusLp = new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT,
            FrameLayout.LayoutParams.MATCH_PARENT
        );
        root.addView(statusView, statusLp);

        setContentView(root);
        setupWebView();
        webView.loadUrl(KIOSK_URL);
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) {
            applyImmersiveMode();
        }
    }

    @Override
    protected void onDestroy() {
        if (webView != null) {
            webView.destroy();
            webView = null;
        }
        super.onDestroy();
    }

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_BACK) {
            return true;
        }
        return super.onKeyDown(keyCode, event);
    }

    private void setupWebView() {
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setUseWideViewPort(true);
        settings.setLoadWithOverviewMode(true);
        // 车间电视改版后需立即生效：不使用缓存，每次都拉服务器最新大屏
        settings.setCacheMode(WebSettings.LOAD_NO_CACHE);
        webView.clearCache(true);

        webView.setBackgroundColor(Color.parseColor("#04080f"));

        webView.setWebChromeClient(new WebChromeClient());

        webView.setWebViewClient(
            new WebViewClient() {
                @Override
                public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                    String url = request.getUrl().toString();
                    if (url.contains("gccd.xnssoft.com") || url.contains("xnssoft.com")) {
                        return false;
                    }
                    return true;
                }

                @Override
                public void onPageFinished(WebView view, String url) {
                    statusView.setVisibility(View.GONE);
                    mainHandler.postDelayed(MainActivity.this::applyImmersiveMode, 300);
                }

                @Override
                public void onReceivedError(
                    WebView view,
                    int errorCode,
                    String description,
                    String failingUrl
                ) {
                    statusView.setVisibility(View.VISIBLE);
                    statusView.setText("加载失败，请检查网络\n" + failingUrl + "\n\n按遥控器确认键重试");
                }
            }
        );
    }

    @Override
    public boolean onKeyUp(int keyCode, KeyEvent event) {
        if (
            keyCode == KeyEvent.KEYCODE_DPAD_CENTER
                || keyCode == KeyEvent.KEYCODE_ENTER
                || keyCode == KeyEvent.KEYCODE_BUTTON_A
        ) {
            if (statusView.getVisibility() == View.VISIBLE) {
                statusView.setText("正在重新加载…");
                webView.reload();
                return true;
            }
        }
        return super.onKeyUp(keyCode, event);
    }

    private void applyImmersiveMode() {
        View decorView = getWindow().getDecorView();
        decorView.setSystemUiVisibility(
            View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
        );
    }
}
