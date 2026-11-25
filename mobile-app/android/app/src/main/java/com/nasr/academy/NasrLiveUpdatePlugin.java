package com.nasr.academy;

import com.getcapacitor.*;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.net.*;
import java.io.*;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.*;
import java.util.concurrent.atomic.AtomicBoolean;

@CapacitorPlugin(name="NasrLiveUpdate")
public class NasrLiveUpdatePlugin extends Plugin {
    private final ExecutorService worker=Executors.newSingleThreadExecutor();
    private final AtomicBoolean busy=new AtomicBoolean();
    private HttpURLConnection connect(String kind,long sequence) throws Exception {
        URL base=new URL(ReleaseTrust.ENDPOINT),url=new URL((ReleaseTrust.ENDPOINT+(kind.equals("bundle")?"&release="+sequence:"")).replace("nasr_mobile_release=manifest","nasr_mobile_release="+kind));
        if(!url.getProtocol().equals("https")||!url.getHost().equals(base.getHost())||url.getPort()!=base.getPort())throw new SecurityException("Untrusted update origin");
        HttpURLConnection connection=(HttpURLConnection)url.openConnection();connection.setInstanceFollowRedirects(false);connection.setUseCaches(false);
        connection.setConnectTimeout(8000);connection.setReadTimeout(20000);connection.setRequestProperty("Cache-Control","no-cache");
        if(connection.getResponseCode()!=200){connection.disconnect();throw new IOException("Update endpoint unavailable");}return connection;
    }
    @PluginMethod public void ready(PluginCall call){try{((MainActivity)getActivity()).updates().ready();call.resolve();}catch(Exception e){call.reject("Interface readiness could not be confirmed");}}
    @PluginMethod public void check(PluginCall call){
        if(!busy.compareAndSet(false,true)){call.resolve(new JSObject().put("status","busy"));return;}
        worker.execute(()->{
            try{
                UpdateEngine engine=((MainActivity)getActivity()).updates();HttpURLConnection manifest=connect("manifest",0);String raw;
                try{raw=new String(UpdateEngine.read(manifest.getInputStream(),262144),StandardCharsets.UTF_8);}finally{manifest.disconnect();}
                UpdateEngine.Release release=engine.verify(raw);JSObject result=new JSObject();
                if(engine.needs(release)){
                    HttpURLConnection bundle=connect("bundle",release.sequence);try{engine.stage(release,bundle.getInputStream());}finally{bundle.disconnect();}
                    result.put("status","ready");result.put("version",release.version);
                }else{result.put("status","current");}
                call.resolve(result);
            }catch(Exception error){call.resolve(new JSObject().put("status","unavailable"));}
            finally{busy.set(false);}
        });
    }
    @Override protected void handleOnDestroy(){worker.shutdown();super.handleOnDestroy();}
}
