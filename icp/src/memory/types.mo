module {
  public type MemoryRecord = {
    user_id : Text;
    session_id : Text;
    content : Text;
    timestamp : Int;
    metadata : ?Text;
  };

  public type StoreRequest = {
    user_id : Text;
    session_id : Text;
    content : Text;
    metadata : ?Text;
  };

  public type MemoryResponse = {
    id : Text;
    user_id : Text;
    session_id : Text;
    content : Text;
    timestamp : Int;
    metadata : ?Text;
  };
};
