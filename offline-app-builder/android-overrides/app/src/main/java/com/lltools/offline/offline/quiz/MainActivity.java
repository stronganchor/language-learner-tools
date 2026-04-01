package com.lltools.offline.offline.quiz;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;
import com.lltools.offline.offline.quiz.stt.LLToolsOfflineSttPlugin;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(LLToolsOfflineSttPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
