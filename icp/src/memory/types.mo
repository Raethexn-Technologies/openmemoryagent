module {

  // Sensitivity classification for memory records.
  //   Public    — safe general facts; visible to any caller and the HTTP endpoint.
  //   Private   — personal but non-critical; readable only by the owner (msg.caller).
  //   Sensitive — potentially harmful if exposed; readable only by owner AND requires
  //               explicit user approval in the browser before the write is signed.
  //
  // The agent (via Laravel + adapter) can only read Public records, so truly private
  // facts never reach the LLM without the user explicitly making them public.
  public type MemoryType = { #Public; #Private; #Sensitive };

  public type MemoryRecord = {
    user_id     : Text;
    session_id  : Text;
    content     : Text;
    timestamp   : Int;
    metadata    : ?Text;
    memory_type : MemoryType;
  };

  // user_id is intentionally absent — the canister derives it from msg.caller.
  // memory_type defaults to #Public if absent.
  public type StoreRequest = {
    session_id  : Text;
    content     : Text;
    metadata    : ?Text;
    memory_type : ?MemoryType;
  };

  public type MemoryResponse = {
    id          : Text;
    user_id     : Text;
    session_id  : Text;
    content     : Text;
    timestamp   : Int;
    metadata    : ?Text;
    memory_type : MemoryType;
  };

  // ─── HTTP gateway types (IC interface spec) ───────────────────────
  public type HeaderField = (Text, Text);

  public type HttpRequest = {
    method  : Text;
    url     : Text;
    headers : [HeaderField];
    body    : Blob;
  };

  public type HttpResponse = {
    status_code        : Nat16;
    headers            : [HeaderField];
    body               : Blob;
    streaming_strategy : ?StreamingStrategy;
    upgrade            : ?Bool;
  };

  // Streaming is never used here; type is required by the IC interface.
  public type StreamingStrategy = {
    #Callback : {
      callback : shared query () -> async ();
      token    : {};
    };
  };
};
