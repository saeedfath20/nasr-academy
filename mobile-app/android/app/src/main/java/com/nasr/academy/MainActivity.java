package com.nasr.academy;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.ServerPath;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import java.io.File;

public class MainActivity extends BridgeActivity {
    private UpdateEngine updates;
    private final Handler watchdog=new Handler(Looper.getMainLooper());
    public UpdateEngine updates(){return updates;}
    @Override public void onCreate(Bundle savedInstanceState){
        registerPlugin(NasrLiveUpdatePlugin.class);
        try{
            long binary=getPackageManager().getPackageInfo(getPackageName(),0).versionCode;
            updates=new UpdateEngine(new File(getNoBackupFilesDir(),"nasr-interface-updates"),ReleaseTrust.PUBLIC_KEY,ReleaseTrust.ABI,getPackageName(),ReleaseTrust.SEQUENCE,binary);
            File candidate=updates.launch();if(candidate!=null)bridgeBuilder.setServerPath(new ServerPath(ServerPath.PathType.BASE_PATH,candidate.getAbsolutePath()));
        }catch(Exception error){updates=null;}
        super.onCreate(savedInstanceState);
        if(updates!=null&&updates.trial())watchdog.postDelayed(()->{if(updates.trial())try{updates.rollback();recreate();}catch(Exception ignored){}},30000);
    }
    @Override public void onDestroy(){watchdog.removeCallbacksAndMessages(null);super.onDestroy();}
}
